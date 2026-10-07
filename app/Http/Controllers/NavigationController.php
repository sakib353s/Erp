<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\NavigationBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Personal navigation: pin/unpin a destination (§18.3 "favorites").
 *
 * The sidebar shows the curated sections; each user then pins the handful of
 * screens they actually live in. Pins are persisted server-side and are always
 * re-filtered against live permissions when rendered, so a revoked permission
 * can never leave a stale link behind.
 */
class NavigationController extends Controller
{
    public function __construct(protected NavigationBuilder $navigation) {}

    public function pin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer'],
        ]);

        // Only ever pin a row the caller could actually open — an IDOR probe
        // must not create a permanent link to a forbidden screen.
        $item = MenuItem::query()
            ->whereKey($data['menu_item_id'])
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereNotNull('route')
            ->first();

        abort_if($item === null, 404);

        if (! app(\App\Domain\Foundation\Services\PermissionCatalog::class)->allows(
            $request->user(),
            $item->permission?->key ?? 'dashboard.view',
        )) {
            abort(403);
        }

        $pinned = $this->navigation->toggleFavorite($request->user(), $item->id);

        return back()->with(
            'status',
            $pinned
                ? "{$item->label} pinned to the top of your navigation."
                : "{$item->label} removed from your pins.",
        );
    }
}
