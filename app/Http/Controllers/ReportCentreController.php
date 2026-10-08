<?php

namespace App\Http\Controllers;

use App\Domain\Reporting\CustomReportBuilder;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportRegistry;
use App\Domain\Reporting\ReportRun;
use App\Domain\Reporting\ScheduledReport;
use App\Domain\Reporting\ScheduledReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The report centre (§13-01…§13-12): one page that names every report the
 * installation can actually open, then nine family pages, then the two pages
 * that are about reports rather than of them — the custom builder's register and
 * the schedules.
 *
 * The centre does not decide what exists. {@see ReportRegistry} reads the router:
 * an entry without a registered route is not listed, and the permission shown on
 * a row is the one the report's own route enforces. That is deliberate — a hub
 * that lists reports the app does not have, or hides ones it does, teaches people
 * to distrust the menu.
 */
class ReportCentreController extends Controller
{
    public function __construct(
        protected ReportRegistry $registry,
        protected ScheduledReportService $schedules,
    ) {}

    /** §13-01…§13-09: the index of the centre — the catalogue's own families. */
    public function index(Request $request): View
    {
        return view('reports.index', [
            'families' => $this->registry->families($request->user()),
            'total' => $this->registry->total(),
            'dangling' => $this->registry->dangling(),
            'schedules' => ScheduledReport::query()
                ->where('company_id', $request->user()->company_id)
                ->where('is_active', true)
                ->count(),
        ]);
    }

    /** One family hub — the catalogue leaves 13-01…13-09. */
    public function family(Request $request, string $family): View
    {
        $definition = $this->registry->family($family);

        abort_if($definition === null, 404);

        $entries = $this->registry->forUser($request->user(), $family);

        return view('reports.family', [
            'family' => $definition + ['slug' => $family],
            'entries' => $entries,
            'openable' => count(array_filter($entries, fn (array $row): bool => $row['allowed'])),
            'families' => $this->registry->families($request->user()),
            'schedules' => ScheduledReport::query()
                ->where('company_id', $request->user()->company_id)
                ->where('is_active', true)
                ->count(),
        ]);
    }

    /** §13-11: the saved custom reports this company has, and their run history. */
    public function custom(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $definitions = ReportDefinition::query()
            ->where('company_id', $companyId)
            ->withCount(['savedFilters', 'runs'])
            ->orderBy('name')
            ->get();

        return view('reports.custom', [
            'definitions' => $definitions,
            'runs' => ReportRun::query()
                ->where('company_id', $companyId)
                ->with(['reportDefinition', 'triggeredBy'])
                ->latest('id')
                ->limit(25)
                ->get(),
            'schedules' => ScheduledReport::query()
                ->where('company_id', $companyId)
                ->with('reportDefinition')
                ->orderBy('name')
                ->get(),
            // The builder is where a definition is actually written — this page
            // is its register, not a second builder.
            'sources' => array_keys(CustomReportBuilder::SOURCES),
        ]);
    }

    /** §13-12: what is scheduled to run, what it produced, and what failed. */
    public function scheduled(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        return view('reports.scheduled', [
            'schedules' => ScheduledReport::query()
                ->where('company_id', $companyId)
                ->with(['reportDefinition', 'creator'])
                ->orderBy('next_run_at')
                ->get(),
            'definitions' => ReportDefinition::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(),
            'runs' => ReportRun::query()
                ->where('company_id', $companyId)
                ->whereNotNull('scheduled_report_id')
                ->with('scheduledReport')
                ->latest('id')
                ->limit(25)
                ->get(),
            'due' => ScheduledReport::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('next_run_at', '<=', now())
                ->count(),
        ]);
    }

    /**
     * Write a schedule. The service is the one that decides what a schedule is —
     * frequency, next run, the audit row — so this action validates the shape of
     * the request and hands the rest over. A definition belonging to another
     * company is not found, which is the refusal that matters.
     */
    public function storeSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'definition_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'string', Rule::in(ScheduledReport::FREQUENCIES)],
        ]);

        $definition = ReportDefinition::query()
            ->where('company_id', $request->user()->company_id)
            ->whereKey($data['definition_id'])
            ->firstOrFail();

        $this->schedules->schedule($request->user(), $definition, $data['name'], $data['frequency']);

        return back()->with('status', sprintf(
            'Schedule “%s” created — it runs %s from now on.',
            $data['name'],
            $data['frequency'],
        ));
    }
}
