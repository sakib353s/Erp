<?php

namespace App\Search;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use Illuminate\Support\Collection;

/**
 * Global search (D17 / §16-49): company-scoped, branch-scoped and
 * permission-filtered at query time.
 *
 * Three filters, in this order, and the order matters:
 *
 *  1. **the company** — the index row carries its company, so a row written for
 *     one tenant can never be read by another even if the ids collide;
 *  2. **the branch** — a person assigned to two outlets sees those two outlets'
 *     records plus the company-wide ones (a shared product, a supplier, a
 *     customer); `accessibleBranchIds() === null` means unconstrained;
 *  3. **the permission** — every hit names the key that admits somebody to the
 *     record it points at, and a hit whose key the reader does not hold is
 *     dropped. This is the filter that has to be right: without it the search box
 *     becomes a way to read a screen the reader may not open, one title at a
 *     time.
 *
 * The permission check happens in PHP against the cached permission catalogue
 * rather than as a SQL `whereIn`, because role changes invalidate that cache and
 * a query copy of the key list is exactly the kind of thing that goes stale
 * without anybody noticing.
 */
class SearchService
{
    public function __construct(protected PermissionCatalog $catalog) {}

    /**
     * @param  int  $limit
     * @return Collection<int, SearchIndex>
     */
    public function search(?User $user, string $query, int $limit = 40): Collection
    {
        $query = trim($query);

        if ($user === null || $query === '' || mb_strlen($query) < 2) {
            return collect();
        }

        $normalized = mb_strtolower($query);

        $builder = SearchIndex::query()
            ->where('company_id', $user->company_id)
            ->where(function ($q) use ($normalized, $query) {
                $q->where('normalized', 'like', '%'.$normalized.'%')
                    ->orWhere('title', 'like', '%'.$query.'%');
            });

        $branchIds = $user->accessibleBranchIds();

        if ($branchIds !== null) {
            $builder->where(function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->orWhereNull('branch_id');
            });
        }

        // The permission filter runs in PHP (see the class note), which means it
        // can only drop rows — so the fetch deliberately oversamples by three and
        // the caller's limit is applied to what survives. Without this, a reader
        // whose role hides a third of the matches would get a two-thirds-full
        // page and a short count, which reads as "the records are not there".
        $hits = $builder
            ->orderBy('title')
            ->limit($limit * 3)
            ->get();

        return $hits
            ->filter(fn (SearchIndex $hit) => $hit->permission_key === null
                || $this->catalog->allows($user, $hit->permission_key)
                || ($user->isSuperAdmin()))
            ->take($limit)
            ->values();
    }

    /**
     * §16-49 — the small, flat answer the ⌘K palette types into.
     *
     * The page can afford a long list; a palette cannot afford a short one. It is
     * the same read, the same filters and the same order as the page — only the
     * limit differs — so a hit that appears in the palette always appears on the
     * page, and the palette never becomes a second, differently-ruled search.
     *
     * @return array<int, array{type:string,kind:string,title:string,subtitle:?string,excerpt:?string,url:string}>
     */
    public function quick(?User $user, string $query, int $limit = 8): array
    {
        return $this->search($user, $query, $limit)
            ->map(fn (SearchIndex $hit) => [
                'type' => (string) $hit->entity_type,
                'kind' => SearchIndex::label((string) $hit->entity_type),
                'title' => (string) $hit->title,
                'subtitle' => $hit->subtitle,
                'excerpt' => $hit->excerpt,
                'url' => (string) $hit->url,
            ])
            ->values()
            ->all();
    }

    /** Distinct permission keys present in the index (for rebuild stats). */
    public function permissionKeys(): array
    {
        return SearchIndex::query()->distinct()->pluck('permission_key')->filter()->values()->all();
    }
}
