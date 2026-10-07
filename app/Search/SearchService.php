<?php

namespace App\Search;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use Illuminate\Support\Collection;

/**
 * Global search (D17): company-scoped, branch-scoped, permission-
 * filtered at query time. Field-level permission is enforced via the
 * permission_key column; branch scope via the actor's accessible
 * branch ids (null = all branches).
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
            ->where(function ($q) use ($normalized) {
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

        $hits = $builder
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return $hits
            ->filter(fn (SearchIndex $hit) => $hit->permission_key === null
                || $this->catalog->allows($user, $hit->permission_key)
                || ($user->isSuperAdmin()))
            ->values();
    }

    /** Distinct permission keys present in the index (for rebuild stats). */
    public function permissionKeys(): array
    {
        return SearchIndex::query()->distinct()->pluck('permission_key')->filter()->values()->all();
    }
}
