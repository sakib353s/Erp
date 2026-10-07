<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\ProductImport;
use App\Domain\Inventory\Services\ProductImportService;
use App\Http\Requests\StoreProductImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catalogue import and its receipt (§04-12).
 *
 * The screen offers two buttons because they are two different decisions: a
 * preview says what the file would do, and only the import button writes. Both
 * paths are the same walk through `ProductImportService` — the preview rolls its
 * own rows back — so a preview cannot promise something the import then fails to
 * deliver.
 */
class ProductImportController extends Controller
{
    public function __construct(protected ProductImportService $imports) {}

    public function create(Request $request): View
    {
        return view('inventory.products.import', [
            'columns' => $this->imports->templateHeadings(),
            'runs' => ProductImport::query()
                ->where('company_id', $request->user()->company_id)
                ->recentFirst()
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(StoreProductImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $dryRun = $request->input('mode') === 'preview';

        try {
            $run = $this->imports->run($request->user(), $file, $dryRun);
        } catch (RuntimeException $e) {
            // Structural problems (no headings, a file with no rows, too big)
            // are not a run: nothing was read, so there is nothing to record.
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.import.show', $run)
            ->with('status', $dryRun
                ? 'Preview ready: nothing was written.'
                : "Import finished: {$run->rows_created} created, {$run->rows_updated} updated, {$run->rows_failed} failed.");
    }

    public function show(Request $request, ProductImport $import): View
    {
        $this->assertSameCompany($request, $import);

        return view('inventory.products.import-show', [
            'run' => $import->load('actor:id,name'),
            'problems' => $import->errors ?? [],
        ]);
    }

    public function history(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:preview,imported,imported_with_errors'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));

        $query = ProductImport::query()
            ->where('company_id', $request->user()->company_id)
            ->with('actor:id,name')
            ->recentFirst();

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if ($search !== '') {
            $query->where('original_name', 'like', "%{$search}%");
        }

        $companyId = $request->user()->company_id;

        return view('inventory.products.import-history', [
            'runs' => $query->paginate(20)->withQueryString(),
            'filters' => ['status' => $data['status'] ?? null, 'q' => $search],
            'totals' => [
                'runs' => ProductImport::query()->where('company_id', $companyId)->count(),
                'created' => (int) ProductImport::query()->where('company_id', $companyId)->sum('rows_created'),
                'updated' => (int) ProductImport::query()->where('company_id', $companyId)->sum('rows_updated'),
                'failed' => (int) ProductImport::query()->where('company_id', $companyId)->sum('rows_failed'),
            ],
        ]);
    }

    /** The template is the contract: these headings, with two example rows. */
    public function template(): StreamedResponse
    {
        $headings = $this->imports->templateHeadings();
        $rows = $this->imports->templateRows();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'product-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function assertSameCompany(Request $request, ProductImport $import): void
    {
        abort_unless(
            (int) $import->company_id === (int) $request->user()?->company_id,
            404,
            'That import does not exist.',
        );
    }
}
