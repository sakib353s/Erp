<?php

namespace App\Http\Controllers;

use App\Domain\Reporting\CustomReportBuilder;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportRun;
use App\Domain\Reporting\ReportRunService;
use App\Domain\Reporting\SavedFilterService;
use App\Domain\Reporting\ScheduledReport;
use App\Domain\Reporting\ScheduledReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Custom sales report builder (02-120): DB-driven definitions, saved
 * filters, schedules and run history. Every read path is company-scoped
 * and runs through CustomReportBuilder, which applies the running user's
 * branch scope server-side on every query.
 */
class CustomReportController extends Controller
{
    public function __construct(
        protected CustomReportBuilder $builder,
        protected ReportRunService $runs,
        protected SavedFilterService $savedFilters,
        protected ScheduledReportService $schedules,
    ) {}

    public function index(Request $request): View
    {
        return view('sales.reports.custom', $this->viewData($request));
    }

    public function run(Request $request): View
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string'],
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $result = $this->builder->run(
            $request->user(),
            $data['source'],
            $data['columns'],
            $data['filters'] ?? [],
            $data['limit'] ?? null,
        );

        return view('sales.reports.custom', $this->viewData($request, $result));
    }

    public function storeDefinition(Request $request)
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('report_definitions', 'code')->where(
                    fn ($query) => $query->where('company_id', $request->user()->company_id),
                ),
            ],
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', 'string', Rule::in(array_keys(CustomReportBuilder::SOURCES))],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string'],
            'filters' => ['nullable', 'array'],
        ]);

        $this->assertWhitelisted($data['source'], $data['columns'], $data['filters'] ?? []);

        ReportDefinition::query()->create([
            'company_id' => $request->user()->company_id,
            'code' => $data['code'],
            'name' => $data['name'],
            'source' => $data['source'],
            'columns' => array_values(array_unique($data['columns'])),
            'filters' => $data['filters'] ?? [],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', sprintf('Report definition "%s" saved.', $data['name']));
    }

    public function storeSavedFilter(Request $request)
    {
        $data = $request->validate([
            'definition_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'payload' => ['nullable', 'array'],
        ]);

        $definition = $this->findDefinition($request, (int) $data['definition_id']);

        $this->savedFilters->save($request->user(), $definition, $data['name'], $data['payload'] ?? []);

        return back()->with('status', sprintf('Filter "%s" saved.', $data['name']));
    }

    public function storeSchedule(Request $request)
    {
        $data = $request->validate([
            'definition_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'frequency' => ['required', 'string', Rule::in(ScheduledReport::FREQUENCIES)],
        ]);

        $definition = $this->findDefinition($request, (int) $data['definition_id']);

        $this->schedules->schedule($request->user(), $definition, $data['name'], $data['frequency']);

        return back()->with('status', sprintf('Schedule "%s" created (%s).', $data['name'], $data['frequency']));
    }

    public function triggerRun(Request $request)
    {
        $data = $request->validate([
            'definition_id' => ['required', 'integer'],
        ]);

        $definition = $this->findDefinition($request, (int) $data['definition_id']);

        $run = $this->runs->run($request->user(), $definition);

        $message = $run->status === 'completed'
            ? sprintf('Run #%d completed: %d row(s).', $run->id, $run->row_count)
            : sprintf('Run #%d failed: %s', $run->id, (string) $run->error);

        return back()->with('status', $message);
    }

    private function findDefinition(Request $request, int $id): ReportDefinition
    {
        return ReportDefinition::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }

    private function assertWhitelisted(string $source, array $columns, array $filters): void
    {
        $errors = [];

        foreach ($columns as $column) {
            if (! in_array($column, $this->builder->columnKeys($source), true)) {
                $errors['columns'][] = "Unknown column: {$column}.";
            }
        }

        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $this->builder->filterKeys($source), true)) {
                $errors['filters'][] = "Unknown filter: {$key}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function viewData(Request $request, ?array $result = null): array
    {
        $user = $request->user();

        $definitions = ReportDefinition::query()
            ->with('savedFilters')
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        $selected = $definitions->firstWhere('id', (int) $request->query('definition', 0));

        $source = $result['source']
            ?? $request->query('source')
            ?? $selected?->source
            ?? 'invoices';

        if (! isset(CustomReportBuilder::SOURCES[$source])) {
            $source = 'invoices';
        }

        $schema = $this->builder->schema($source);

        if ($result !== null) {
            $currentColumns = array_column($result['columns'], 'key');
            $currentFilters = $result['filters'];
        } else {
            $currentColumns = $selected !== null && $selected->source === $source
                ? $selected->columns
                : [];
            $currentFilters = $request->query('filters', []);
            if (! is_array($currentFilters)) {
                $currentFilters = [];
            }
        }

        $savedFilters = $selected instanceof ReportDefinition
            ? $this->savedFilters->list($user, $selected)
            : collect();

        return [
            'sources' => $this->builder->sources(),
            'schema' => $schema,
            'currentSource' => $source,
            'currentColumns' => $currentColumns,
            'currentFilters' => $currentFilters,
            'definitions' => $definitions,
            'selectedDefinition' => $selected,
            'savedFilters' => $savedFilters instanceof Collection ? $savedFilters : collect($savedFilters),
            'schedules' => ScheduledReport::query()
                ->with('reportDefinition')
                ->where('company_id', $user->company_id)
                ->latest()
                ->limit(20)
                ->get(),
            'recentRuns' => ReportRun::query()
                ->with(['reportDefinition', 'triggeredBy'])
                ->where('company_id', $user->company_id)
                ->latest()
                ->limit(10)
                ->get(),
            'result' => $result,
            'branchScope' => $user->accessibleBranchIds(),
        ];
    }
}
