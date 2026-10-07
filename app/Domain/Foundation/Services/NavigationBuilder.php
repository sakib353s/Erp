<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Database-driven sidebar/navigation builder (Rule 7, spec section F).
 *
 * The sidebar renders ONLY entries that are:
 *  - status='active' (planned catalog rows never render — no dead links),
 *  - is_active,
 *  - permitted for the user's effective permissions,
 *  - allowed for the current portal,
 *  - covered by the company's feature entitlements.
 * Unauthorized items are omitted entirely — never shown disabled.
 */
class NavigationBuilder
{
    public function __construct(
        protected PermissionCatalog $permissions,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * @return array<int, array{code:string,label:string,label_key:?string,route:?string,icon:?string,children:array}>
     */
    public function sidebar(?User $user, string $portal = 'erp', ?string $currentPath = null): array
    {
        if ($user === null) {
            return [];
        }

        $items = MenuItem::query()
            ->with('permission')
            ->where('location', 'sidebar')
            ->where('status', 'active')
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('label')
            ->get();

        $portal = $this->portalFilter($items, $portal);

        $visible = $items
            ->filter(fn (MenuItem $item) => $this->visible($item, $user, $portal))
            ->groupBy('parent_id');

        return $this->tree($visible, $currentPath);
    }

    /** Utility/header entries (approvals inbox, notifications…) — same rules. */
    public function entries(string $location, ?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $items = MenuItem::query()
            ->where('location', $location)
            ->where('status', 'active')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        $portal = $this->portalFilter($items, $portal);

        return $items
            ->filter(fn (MenuItem $item) => $this->visible($item, $user, $portal))
            ->map(fn (MenuItem $item) => [
                'code' => $item->code,
                'label' => $item->label,
                'label_key' => $item->label_key,
                'route' => $item->route,
                'icon' => $item->icon,
            ])
            ->values()
            ->all();
    }

    protected function visible(MenuItem $item, User $user, array $portal): bool
    {
        if ($item->feature_key !== null && ! $this->entitlements->has($item->feature_key)) {
            return false;
        }

        // Groups (route-less sections) are judged by their children in
        // branch(): their own permission row is a case-mangled derivation
        // (`sales.Orders.view`) that must not orphan visible leaves.
        if ($item->route !== null
            && $item->permission_id !== null
            && ! $this->permissions->allows($user, $item->permission?->key ?? '')) {
            return false;
        }

        // Items linked to specific portals are only visible in those portals.
        if ($portal['any'] && ! in_array($item->id, $portal['restricted'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Portal filtering: an item with NO pivot rows is visible in every
     * portal; an item linked to portals is visible only in those.
     *
     * @return array{restricted: array<int,int>, any: bool}
     */
    protected function portalFilter($items, string $portalCode): array
    {
        $itemIds = $items->pluck('id')->all();

        if ($itemIds === []) {
            return ['restricted' => [], 'any' => false];
        }

        $restricted = DB::table('menu_item_portal')
            ->whereIn('menu_item_id', $itemIds)
            ->distinct()
            ->pluck('menu_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($restricted === []) {
            return ['restricted' => [], 'any' => false];
        }

        $allowed = DB::table('menu_item_portal as mip')
            ->join('portals as p', 'p.id', '=', 'mip.portal_id')
            ->whereIn('mip.menu_item_id', $restricted)
            ->where('p.code', $portalCode)
            ->pluck('mip.menu_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['restricted' => $allowed, 'any' => true];
    }

    /**
     * Build the sidebar forest from the parent-grouped item map.
     *
     * @param  Collection<int|string, Collection<int, \MenuItem>>  $grouped  parent_id => items
     * @return array<int, array{code:string,label:string,label_key:?string,route:?string,icon:?string,children:array}>
     */
    protected function tree($grouped, ?string $currentPath): array
    {
        return $this->branch($grouped, $grouped->get(null, collect()), $currentPath);
    }

    /**
     * Build one level of the forest: $siblings is the flat collection of
     * items sharing the same parent, $grouped holds every level so each
     * child can look up its own children.
     *
     * @param  Collection<int|string, Collection<int, \MenuItem>>  $grouped
     * @param  Collection<int, \MenuItem>  $siblings
     * @return array<int, array{code:string,label:string,label_key:?string,route:?string,icon:?string,children:array}>
     */
    protected function branch($grouped, $siblings, ?string $currentPath): array
    {
        $nodes = [];

        foreach ($siblings as $item) {
            $children = $this->branch($grouped, $grouped->get($item->id, collect()), $currentPath);

            // A branch node with no visible children is hidden (no dead parents).
            if ($item->route === null && $children === []) {
                continue;
            }

            // '#anchor'/'?query' routes highlight their base page too.
            $base = $item->route !== null
                ? explode('?', explode('#', $item->route)[0])[0]
                : null;

            $nodes[$item->id] = [
                'id' => $item->id,
                'code' => $item->code,
                'label' => $item->label,
                'label_key' => $item->label_key,
                'route' => $item->route,
                'icon' => $item->icon,
                'sort' => $item->sort,
                'children' => $children,
                'current' => $base !== null && $currentPath !== null
                    && ($currentPath === $base || str_starts_with($currentPath, rtrim($base, '/').'/')),
            ];
        }

        uasort($nodes, fn ($a, $b) => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);

        return array_values($nodes);
    }
}
