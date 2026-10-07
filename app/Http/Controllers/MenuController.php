<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Navigation registry admin: shows the FULL imported catalog including
 * planned entries (which never render in the sidebar), and lets an
 * authorised admin flip status once a page exists. Every flip is
 * audited (menu.status_changed).
 */
class MenuController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(Request $request): View
    {
        $items = MenuItem::query()
            ->with(['module', 'permission'])
            ->orderBy('location')
            ->orderBy('sort')
            ->orderBy('label')
            ->get();

        $locationFilter = (string) $request->query('location', 'sidebar');

        return view('menus.index', [
            'modules' => Module::query()->orderBy('sort')->get(),
            'items' => $items->where('location', $locationFilter)->values(),
            'locations' => config('erp.navigation.locations'),
            'location' => $locationFilter,
            'counts' => [
                'active' => $items->where('status', 'active')->count(),
                'planned' => $items->where('status', 'planned')->count(),
            ],
        ]);
    }

    public function toggleStatus(Request $request, MenuItem $item): RedirectResponse
    {
        $before = $item->status;
        $item->status = $before === 'active' ? 'planned' : 'active';
        $item->save();

        $this->audit->record([
            'action' => 'menu.status_changed',
            'entity_type' => 'menu_item',
            'entity_id' => $item->id,
            'actor_id' => $request->user()->id,
            'before' => ['status' => $before],
            'after' => ['status' => $item->status, 'code' => $item->code],
            'ip' => (string) $request->ip(),
        ]);

        return back()->with('status', "{$item->label} is now {$item->status}.");
    }
}
