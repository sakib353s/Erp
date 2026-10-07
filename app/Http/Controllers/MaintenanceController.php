<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Search\SearchIndexRebuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * System maintenance landing page + the safe rebuild-index action
 * (row 15-28). Rebuild is allowed self-heal: source tables only,
 * permission-gated, fully audited.
 */
class MaintenanceController extends Controller
{
    public function __construct(
        protected SearchIndexRebuilder $rebuilder,
        protected AuditRecorder $audit,
    ) {}

    public function index(): View
    {
        return view('maintenance.index');
    }

    public function rebuildIndex(\Illuminate\Http\Request $request): RedirectResponse
    {
        $before = \App\Search\SearchIndex::query()->count();

        $counts = $this->rebuilder->rebuild((int) $request->user()->company_id);

        $after = \App\Search\SearchIndex::query()->count();

        $this->audit->record([
            'action' => 'maintenance.rebuild_index',
            'entity_type' => 'search_index',
            'entity_id' => null,
            'actor_id' => $request->user()->id,
            'before' => ['rows' => $before],
            'after' => ['rows' => $after, 'by_type' => $counts],
            'ip' => (string) $request->ip(),
        ]);

        return back()->with('status', "Search index rebuilt — {$after} rows indexed.");
    }
}
