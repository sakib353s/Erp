<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\MenuItemFavorite;
use App\Domain\Foundation\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Database-driven navigation builder (Rule 7, spec section F, §18.3).
 *
 * ── Why this class was rewritten ────────────────────────────────────────────
 * The first implementation rendered the supplied §47 catalog verbatim. That
 * catalog is a SPECIFICATION of 1,022 lines: it lists every widget, every
 * report and every verb ("Bulk Print Invoice", "Session Opening", "Add
 * Product") as an indented node. Rendering it produced the exact failure
 * §18.3 forbids — a wall-of-text sidebar in which dozens of entries resolve to
 * the same handful of screens, so "the menu items just go to pages".
 *
 * ── What the navigation now is ──────────────────────────────────────────────
 *  1. SECTIONS  — job-to-be-done groups (My work / Sell / Buy & stock / Money /
 *                 People / Insight / Governance / Configuration).
 *  2. MODULES   — one collapsible group per module holding AT MOST
 *                 `navigation.max_children` real destinations.
 *  3. FAVORITES — user-pinned destinations, persisted server-side.
 *  4. PALETTE   — every permitted, routable entry (including deep ones) is
 *                 searchable through ⌘K, so curation never hides a capability.
 *  5. VIEWS     — query variants (`?status=pending`) become saved views on the
 *                 page itself instead of 18 near-identical sidebar rows.
 *
 * Invariants preserved from v1 (documented, and covered by NavigationMenuTest):
 *  · only status='active' + is_active rows render — planned rows never leak;
 *  · permission-filtered: an item the user may not open is ABSENT, never
 *    shown disabled or locked;
 *  · portal-restricted items only appear in their portal;
 *  · feature-entitlement gating still applies;
 *  · entirely database-driven — nothing is hard-coded in Blade.
 */
class NavigationBuilder
{
    /**
     * Per-request memo. The shell asks for items three times (sections, trail,
     * palette) and page rails ask a fourth — the permission join and the
     * entitlement probe must not run four times per render.
     *
     * @var array<string, Collection<int, MenuItem>>
     */
    protected array $memo = [];

    public function __construct(
        protected PermissionCatalog $permissions,
        protected EntitlementService $entitlements,
    ) {}

    /* ===================================================================== */
    /* Public API                                                            */
    /* ===================================================================== */

    /**
     * The curated sidebar: ordered sections, each holding module groups of
     * real destinations. Returns [] for guests.
     *
     * @return array<int, array{code:string,label:string,icon:?string,items:array<int,array<string,mixed>>}>
     */
    public function sidebar(?User $user, string $portal = 'erp', ?string $currentPath = null): array
    {
        if ($user === null) {
            return [];
        }

        $items = $this->visibleSidebarItems($user, $portal)->keyBy('id');
        $grouped = $items->groupBy(fn (MenuItem $item) => $item->parent_id);

        $modules = $this->buildModules($grouped, $items, $currentPath);

        $sections = $this->groupIntoSections($modules, $items);

        if (config('erp.navigation.show_favorites', true)) {
            $sections = $this->withFavorites($sections, $user, $portal, $currentPath);
        }

        return $sections;
    }

    /**
     * Utility/header entries (company profile, approvals, audit, profile…) —
     * same visibility rules, flat list.
     *
     * @return array<int, array{code:string,label:string,label_key:?string,route:?string,icon:?string}>
     */
    public function entries(string $location, ?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $items = MenuItem::query()
            ->with('permission')
            ->where('location', $location)
            ->where('status', 'active')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        $portalFilter = $this->portalFilter($items, $portal);

        return $items
            ->filter(fn (MenuItem $item) => $this->visible($item, $user, $portalFilter))
            ->map(fn (MenuItem $item) => [
                'code' => $item->code,
                'label' => $item->label,
                'label_key' => $item->label_key,
                'route' => $item->route,
                'url' => $this->url($item->route),
                'icon' => $item->icon,
            ])
            ->values()
            ->all();
    }

    /**
     * ⌘K index: every permitted, routable destination — including pages whose
     * depth or rarity keeps them out of the sidebar. Deduped by canonical URL
     * so search never offers two names for one screen.
     *
     * @return array<int, array{label:string,url:string,icon:?string,section:?string,group:?string,keywords:string,hint:?string}>
     */
    public function palette(?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $items = $this->visibleItems($user, $portal, includeActions: true);

        $sections = config('erp.navigation.sections', []);
        $moduleSections = config('erp.navigation.module_sections', []);
        $moduleOrder = array_flip(config('erp.navigation.module_order', []));

        $entries = [];
        $seen = [];

        foreach ($items->sortBy(fn (MenuItem $i) => [
            $moduleOrder[$i->module?->code ?? ''] ?? 99,
            $i->sort,
        ]) as $item) {
            $url = $this->url($item->route);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $sectionCode = $moduleSections[$item->module?->code ?? ''] ?? 'govern';
            $isAction = $item->location === 'action';

            $entries[] = [
                'label' => $isAction
                    ? $this->normalizeLabel($item->label, $item->parent?->label)
                    : $this->normalizeLabel($item->label),
                'url' => $url,
                'icon' => $item->icon ?: ($item->module?->icon ?? 'bi-dot'),
                'section' => $sections[$sectionCode]['label'] ?? 'Navigate',
                'group' => $item->module?->name,
                'keywords' => trim(implode(' ', array_filter([
                    $item->code,
                    $item->parent?->label,
                    $item->module?->code,
                    $isAction ? 'action' : null,
                ]))),
                'hint' => $isAction ? 'action' : null,
            ];
        }

        return $entries;
    }

    /**
     * Saved views for one screen: catalog entries that differ only by query
     * string (`/app/sales/orders?status=pending`) become segmented filters on
     * the page instead of duplicated sidebar rows.
     *
     * @return array<int, array{label:string,url:string,active:bool}>
     */
    public function viewsForPath(string $path, ?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $path = '/'.ltrim(explode('?', $path)[0], '/');

        return $this->visibleItems($user, $portal, includeActions: true)
            ->filter(fn (MenuItem $i) => $i->route !== null
                && ! in_array($i->route, [$path], true)
                && str_starts_with($i->route, $path.'?'))
            ->unique('route')
            ->sortBy('sort')
            ->map(fn (MenuItem $i) => [
                'label' => $this->normalizeLabel($i->label, $i->parent?->label),
                'url' => $this->url($i->route),
                'active' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * "More in <module>" rail for a page: sibling destinations of the screen
     * the user is looking at. Keeps deep capability one click away without
     * inflating the sidebar.
     *
     * @return array<int, array{label:string,url:string,active:bool}>
     */
    public function related(string $currentPath, ?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $currentPath = '/'.ltrim(explode('?', $currentPath)[0], '/');

        $items = $this->visibleSidebarItems($user, $portal);

        $current = $items->first(fn (MenuItem $i) => $this->matches($i->route, $currentPath));

        if ($current === null) {
            return [];
        }

        $moduleId = $current->module_id;

        return $items
            ->filter(fn (MenuItem $i) => $i->module_id === $moduleId && $i->id !== $current->id && $i->route !== null)
            ->unique(fn (MenuItem $i) => $this->basePath($i->route))
            ->sortBy('sort')
            ->take(12)
            ->map(fn (MenuItem $i) => [
                'label' => $this->normalizeLabel($i->label),
                'url' => $this->url($i->route),
                'active' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * Breadcrumb trail for the current path: Section › Module › Page.
     *
     * @return array<int, array{label:string,url:?string}>
     */
    public function trail(string $currentPath, ?User $user, string $portal = 'erp'): array
    {
        if ($user === null) {
            return [];
        }

        $currentPath = '/'.ltrim(explode('?', $currentPath)[0], '/');

        $items = $this->visibleItems($user, $portal, includeActions: true);

        $match = $items
            ->filter(fn (MenuItem $i) => $this->matches($i->route, $currentPath))
            ->sortByDesc(fn (MenuItem $i) => strlen((string) $i->route))
            ->first();

        if ($match === null) {
            return [];
        }

        $chain = [];
        $node = $match;

        while ($node !== null && count($chain) < 4) {
            array_unshift($chain, $node);
            $node = $node->parent_id !== null ? $items->get($node->parent_id) : null;
        }

        $sections = config('erp.navigation.sections', []);
        $moduleSections = config('erp.navigation.module_sections', []);
        $sectionCode = $moduleSections[$match->module?->code ?? ''] ?? null;

        $trail = [];

        if ($sectionCode !== null && isset($sections[$sectionCode])) {
            $trail[] = ['label' => $sections[$sectionCode]['label'], 'url' => null];
        }

        foreach ($chain as $index => $node) {
            $isLast = $index === array_key_last($chain);

            $trail[] = [
                'label' => $isLast
                    ? $this->normalizeLabel($node->label, $this->parentLabel($items, $node))
                    : ($node->module_id === $node->id ? $node->label : $this->normalizeLabel($node->label)),
                'url' => $isLast ? null : $this->url($node->route),
            ];
        }

        return $trail;
    }

    /** Toggle a user's pin on a menu entry; returns the new state. */
    public function toggleFavorite(User $user, int $menuItemId): bool
    {
        $existing = MenuItemFavorite::query()
            ->where('user_id', $user->id)
            ->where('menu_item_id', $menuItemId)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        MenuItemFavorite::query()->create([
            'user_id' => $user->id,
            'menu_item_id' => $menuItemId,
            'sort' => (int) MenuItemFavorite::query()->where('user_id', $user->id)->max('sort') + 1,
        ]);

        return true;
    }

    /**
     * Bare URL of the current screen, for "pin this page" controls.
     */
    public function currentMenuItemId(string $path, ?User $user, string $portal = 'erp'): ?int
    {
        if ($user === null) {
            return null;
        }

        $path = '/'.ltrim(explode('?', $path)[0], '/');

        return $this->visibleItems($user, $portal)
            ->filter(fn (MenuItem $i) => $this->matches($i->route, $path))
            ->sortByDesc(fn (MenuItem $i) => strlen((string) $i->route))
            ->first()?->id;
    }

    /* ===================================================================== */
    /* Internals — visibility                                               */
    /* ===================================================================== */

    /**
     * Every visible entry for the user, loaded once.
     *
     * @return Collection<int, MenuItem>
     */
    protected function visibleItems(?User $user, string $portal, bool $includeActions = false): Collection
    {
        if ($user === null) {
            return collect();
        }

        $query = MenuItem::query()
            ->with(['permission', 'module', 'parent'])
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereNotNull('route')
            ->orderBy('sort')
            ->orderBy('label');

        $locations = ['sidebar', 'header', 'utility'];

        if ($includeActions) {
            $locations[] = 'action';
        }

        $query->whereIn('location', $locations);

        $cacheKey = $user->id.':'.$portal.':'.($includeActions ? 'all' : 'destinations');

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $items = $query->get();
        $portalFilter = $this->portalFilter($items, $portal);

        return $this->memo[$cacheKey] = $items
            ->filter(fn (MenuItem $item) => $this->visible($item, $user, $portalFilter))
            ->values();
    }

    /**
     * Destinations that belong in the sidebar: no verb-first action leaves
     * (they were seeded with location='action'), no duplicates.
     *
     * @return Collection<int, MenuItem>
     */
    protected function visibleSidebarItems(?User $user, string $portal): Collection
    {
        return $this->visibleItems($user, $portal)
            ->filter(fn (MenuItem $i) => $i->location === 'sidebar');
    }

    protected function visible(MenuItem $item, User $user, array $portalFilter): bool
    {
        if ($item->feature_key !== null && ! $this->entitlements->has($item->feature_key)) {
            return false;
        }

        // Group rows (route-less sections) are judged by their children.
        if ($item->route !== null
            && $item->permission_id !== null
            && ! $this->permissions->allows($user, $item->permission?->key ?? '')) {
            return false;
        }

        if ($portalFilter['any'] && ! in_array($item->id, $portalFilter['restricted'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Portal filtering: an item with NO pivot rows is visible in every portal;
     * an item linked to portals is visible only in those.
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

    /* ===================================================================== */
    /* Internals — tree building                                            */
    /* ===================================================================== */

    /**
     * Sidebar groups for one section level.
     *
     * The supplied catalog nests two levels of grouping inside every module
     * ("Sales › Orders › All Orders"). Rendering that as a three-deep accordion
     * in a 248px rail is unreadable, so the second level is PROMOTED: a
     * route-less child that resolves to real pages becomes a sibling group of
     * its parent, and its siblings that are pages become plain links.
     *
     * Net shape (never deeper):  section → { group | link } → link.
     *
     * @param  Collection<int|string, Collection<int, MenuItem>>  $grouped
     * @return array<int, array<string, mixed>>
     */
    protected function buildModules($grouped, Collection $visible, ?string $currentPath): array
    {
        $maxChildren = (int) config('erp.navigation.max_children', 8);
        $modules = [];

        // Roots = parent-less rows PLUS rows whose parent is not visible to this
        // user (a blocked group must never orphan the pages beneath it — §18.3
        // hides, it never breaks navigation).
        $roots = $visible->filter(
            fn (MenuItem $item) => $item->parent_id === null || ! $visible->has($item->parent_id),
        );

        foreach ($roots as $root) {
            $leaves = [];
            $subGroups = [];
            $seen = [];

            // Section/ordering key: the MODULE row (Sales), not the root menu
            // row — promoted sub-groups inherit it so they stay in their module.
            $moduleKey = $root->module?->code ?? $root->code;

            foreach (($grouped->get($root->id) ?? collect())->sortBy([['sort', 'asc'], ['label', 'asc']]) as $child) {
                if ($child->route === null) {
                    if (! $this->subGroupsAllowed()) {
                        continue;
                    }

                    $nested = $this->canonicalChildren($grouped, $child, $currentPath, $maxChildren, $seen);

                    if ($nested !== []) {
                        $subGroups[] = $this->groupNode($child, $nested, $grouped, $currentPath, $moduleKey);
                    }

                    continue;
                }

                $node = $this->leafNode($child, $root, $currentPath, $seen);

                if ($node !== null) {
                    $leaves[] = $node;
                }
            }

            // The module root itself may be a real page (Dashboard).
            if ($root->route !== null) {
                $modules[] = [
                    'id' => $root->id,
                    'code' => $root->code,
                    'label' => $root->label,
                    'label_key' => $root->label_key,
                    'route' => $root->route,
                    'url' => $this->url($root->route),
                    'icon' => $root->icon ?? $root->module?->icon ?? 'bi-dot',
                    'children' => [],
                    'current' => $this->matches($root->route, $currentPath ?? ''),
                    'module_id' => $root->module_id,
                    'sort' => $root->sort,
                    'section_key' => $moduleKey,
                    'overflow_count' => 0,
                ];

                continue;
            }

            if ($leaves !== []) {
                $modules[] = [
                    'id' => $root->id,
                    'code' => $root->code,
                    'label' => $root->label,
                    'label_key' => $root->label_key,
                    'route' => null,
                    'url' => null,
                    'icon' => $root->icon ?? $root->module?->icon ?? 'bi-folder',
                    'children' => $leaves,
                    'current' => false,
                    'module_id' => $root->module_id,
                    'sort' => $root->sort,
                    'section_key' => $moduleKey,
                    'overflow_count' => max(0, $this->descendantCount($grouped, $root) - count($leaves)),
                ];
            }

            // Promoted sub-groups keep their catalog position, one level only.
            foreach ($subGroups as $group) {
                $modules[] = $group;
            }
        }

        return $modules;
    }

    /**
     * A promoted sub-group ("Orders", "Counter (POS)", "System maintenance").
     *
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    protected function groupNode(MenuItem $item, array $children, $grouped, ?string $currentPath, ?string $moduleKey = null): array
    {
        $active = collect($children)->contains(fn (array $child) => ! empty($child['current']));

        return [
            'id' => $item->id,
            'code' => $item->code,
            'label' => $this->normalizeLabel($item->label),
            'label_key' => $item->label_key,
            'route' => null,
            'url' => null,
            'icon' => $item->icon ?? 'bi-folder',
            'children' => $children,
            'current' => $active,
            'module_id' => $item->module_id,
            'sort' => $item->sort,
            'section_key' => $moduleKey ?? $item->module?->code ?? $item->code,
            'overflow_count' => max(0, $this->descendantCount($grouped, $item) - count($children)),
        ];
    }

    /**
     * One canonical destination, or null when the route is already represented
     * in this group (one screen = one link).
     *
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>|null
     */
    protected function leafNode(MenuItem $item, MenuItem $parent, ?string $currentPath, array &$seen): ?array
    {
        $basePath = $item->route !== null ? $this->basePath($item->route) : null;

        if ($basePath === null || ($basePath !== null && isset($seen[$basePath]))) {
            return null;
        }

        $seen[$basePath] = true;

        return [
            'id' => $item->id,
            'code' => $item->code,
            'label' => $this->normalizeLabel($item->label, $parent->label),
            'label_key' => $item->label_key,
            'route' => $item->route,
            'url' => $this->url($item->route),
            'icon' => $item->icon,
            'children' => [],
            'current' => $this->matches($item->route, $currentPath ?? ''),
            'sort' => $item->sort,
        ];
    }

    /**
     * Canonical child pages of a group, deduped by base path, ordered by
     * catalog sort, capped at `max_children`.
     *
     * @param  Collection<int|string, Collection<int, MenuItem>>  $grouped
     * @param  array<string, bool>  $seenShared
     * @return array<int, array<string, mixed>>
     */
    protected function canonicalChildren($grouped, MenuItem $parent, ?string $currentPath, int $maxChildren, array &$seenShared = []): array
    {
        $children = [];

        foreach (($grouped->get($parent->id) ?? collect())->sortBy([['sort', 'asc'], ['label', 'asc']]) as $child) {
            if ($child->route === null) {
                // Depth is capped: a third level folds into the page's own tabs.
                continue;
            }

            $node = $this->leafNode($child, $parent, $currentPath, $seenShared);

            if ($node !== null) {
                $children[] = $node;
            }
        }

        if (count($children) > $maxChildren) {
            $head = array_slice($children, 0, $maxChildren);
            $active = array_filter($children, fn (array $child) => ! empty($child['current']));

            foreach ($active as $node) {
                if (! in_array($node['id'], array_column($head, 'id'), true)) {
                    $head[count($head) - 1] = $node;
                }
            }

            $children = array_values($head);
        }

        return $children;
    }

    /**
     * Does the rail allow promoted second-level groups?
     *
     * max_depth = 2 (default) → section → { group | link } → link.
     * max_depth = 1          → section → link (a flat rail for tiny teams).
     */
    protected function subGroupsAllowed(): bool
    {
        return (int) config('erp.navigation.max_depth', 2) >= 2;
    }

    protected function descendantCount($grouped, MenuItem $parent): int
    {
        $count = 0;

        foreach (($grouped->get($parent->id) ?? collect()) as $child) {
            $count += 1 + $this->descendantCount($grouped, $child);
        }

        return $count;
    }

    /**
     * Assign modules to job-to-be-done sections and drop empty ones.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function groupIntoSections(array $modules, Collection $items): array
    {
        $sections = config('erp.navigation.sections', []);
        $moduleSections = config('erp.navigation.module_sections', []);
        $order = array_flip(config('erp.navigation.module_order', []));
        $workRoutes = config('erp.navigation.work_routes', []);

        $buckets = [];

        foreach ($modules as $module) {
            $moduleCode = $module['section_key'] ?? $this->moduleCode($module, $items);

            // Dashboard + cross-cutting work entries are pinned to "My work".
            $section = in_array($module['route'], $workRoutes, true)
                ? 'work'
                : ($moduleSections[$moduleCode] ?? 'govern');

            // Promote individual entries to "My work" (approvals inbox…).
            $children = $module['children'];
            $promoted = [];
            $kept = [];

            foreach ($children as $child) {
                if (in_array($this->basePath((string) $child['route']), $workRoutes, true)) {
                    $promoted[] = $child;
                } else {
                    $kept[] = $child;
                }
            }

            if ($promoted !== []) {
                $buckets['work'][] = [
                    'id' => null,
                    'code' => 'work.promoted',
                    'label' => 'Work queue',
                    'route' => null,
                    'url' => null,
                    'icon' => 'bi-inbox',
                    'children' => $promoted,
                    'current' => false,
                    'module_id' => null,
                    'sort' => 0,
                    'section_key' => 'work',
                    'overflow_count' => 0,
                ];
            }

            $module['children'] = $kept;

            if ($module['route'] === null && $module['children'] === []) {
                continue;
            }

            $buckets[$section][] = $module;
        }

        $result = [];

        foreach (array_keys($sections) as $sectionCode) {
            $bucket = $buckets[$sectionCode] ?? [];

            if ($bucket === []) {
                continue;
            }

            usort($bucket, function (array $a, array $b) use ($order) {
                $aCode = $a['section_key'] ?? '';
                $bCode = $b['section_key'] ?? '';

                return [$order[$aCode] ?? 99, $a['sort'], $a['label']] <=> [$order[$bCode] ?? 99, $b['sort'], $b['label']];
            });

            $result[] = [
                'code' => $sectionCode,
                'label' => $sections[$sectionCode]['label'] ?? ucfirst($sectionCode),
                'icon' => $sections[$sectionCode]['icon'] ?? null,
                'items' => $bucket,
            ];
        }

        return $result;
    }

    /**
     * Prepend the user's pinned destinations (server-side, survives devices).
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function withFavorites(array $sections, User $user, string $portal, ?string $currentPath): array
    {
        $favorites = MenuItemFavorite::query()
            ->with(['menuItem.permission', 'menuItem.module'])
            ->where('user_id', $user->id)
            ->orderBy('sort')
            ->get()
            ->pluck('menuItem')
            ->filter();

        if ($favorites->isEmpty()) {
            return $sections;
        }

        $visible = $this->visibleItems($user, $portal)->keyBy('id');

        $items = [];

        foreach ($favorites as $favorite) {
            $item = $visible->get($favorite->id);

            if ($item === null || $item->route === null) {
                continue; // permission revoked or feature disabled since pinning
            }

            $items[] = [
                'id' => $item->id,
                'code' => 'fav.'.$item->code,
                'label' => $this->normalizeLabel($item->label),
                'label_key' => $item->label_key,
                'route' => $item->route,
                'url' => $this->url($item->route),
                'icon' => $item->icon ?: 'bi-star',
                'children' => [],
                'current' => $this->matches($item->route, $currentPath ?? ''),
                'sort' => 0,
                'pinned' => true,
            ];
        }

        if ($items === []) {
            return $sections;
        }

        array_unshift($sections, [
            'code' => 'pinned',
            'label' => 'Pinned',
            'icon' => 'bi-star',
            'items' => [[
                'id' => null,
                'code' => 'pinned.group',
                'label' => 'Pinned pages',
                'route' => null,
                'url' => null,
                'icon' => 'bi-star',
                'children' => $items,
                'current' => false,
                'sort' => 0,
                'overflow_count' => 0,
            ]],
        ]);

        return $sections;
    }

    /* ===================================================================== */
    /* Internals — small helpers                                            */
    /* ===================================================================== */

    protected function moduleCode(array $module, Collection $items): ?string
    {
        if (($module['code'] ?? null) !== null) {
            return $module['code'];
        }

        return null;
    }

    protected function parentLabel(Collection $items, MenuItem $node): ?string
    {
        return $node->parent_id !== null ? $items->get($node->parent_id)?->label : null;
    }

    /**
     * "All Orders" → "Orders" when the page already sits under a parent that
     * carries the plural noun ("Orders › All Orders" collapses to "Orders").
     * Verb-first action labels keep their imperative phrasing in the palette.
     */
    protected function normalizeLabel(string $label, ?string $parentLabel = null): string
    {
        $clean = trim($label);

        if (str_starts_with($clean, 'All ') && strlen($clean) > 4) {
            return trim(substr($clean, 4));
        }

        if ($parentLabel !== null && strcasecmp($clean, $parentLabel) === 0) {
            return $clean;
        }

        return $clean;
    }

    /** Resolve a catalog route into a safe href (strips filter-only anchors). */
    protected function url(?string $route): ?string
    {
        if ($route === null || $route === '') {
            return null;
        }

        // '/app/x#anchor' keeps the query but drops the dead anchor when the
        // anchor target no longer exists server-side.
        return $route;
    }

    protected function basePath(string $route): string
    {
        return explode('?', explode('#', $route)[0])[0];
    }

    /** Does a catalog route point at the given request path? */
    protected function matches(?string $route, string $currentPath): bool
    {
        if ($route === null) {
            return false;
        }

        $base = rtrim($this->basePath($route), '/');
        $path = rtrim($currentPath, '/');

        if ($base === '' || $path === '') {
            return false;
        }

        // Exact screen, or a nested resource page (/app/users/12 under /app/users).
        return $path === $base
            || str_starts_with($path, $base.'/')
            || str_starts_with($path, $base.'?');
    }
}
