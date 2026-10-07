<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Services\StockReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * Stock report family (§04-35, §04-36): ageing, dead stock and the stock report.
 *
 * Every figure is derived from the movement ledger and the valuation layers, so
 * a report can never agree with anything except the truth of the stock. CSV
 * export uses the same rows the screen shows — filters included.
 */
class InventoryReportController extends Controller
{
    public function __construct(protected StockReportService $reports) {}

    public function aging(Request $request): View|StreamedResponse
    {
        $filters = $this->filters($request);
        $result = $this->reports->aging($this->companyId($request), $filters['warehouse'], $filters['q']);

        if ($this->wantsCsv($request)) {
            return $this->csv('stock-ageing', $result['rows'], [
                'SKU', 'Product', 'Warehouse', 'On hand', 'Reserved', 'Available', 'Value', 'Last movement', 'Days idle', 'Bucket',
            ], fn ($row) => [
                $row['product']->sku,
                $row['product']->name,
                $row['warehouse']?->name,
                $row['on_hand'],
                $row['reserved'],
                $row['available'],
                $row['value'],
                $row['last_movement_at']?->format('Y-m-d H:i'),
                $row['days_idle'],
                $row['bucket'],
            ], $result['totals']);
        }

        return view('inventory.reports.aging', [
            'filters' => $filters,
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'buckets' => $result['buckets'],
            'threshold' => $result['threshold'],
            'warehouses' => $this->reports->warehouses(),
        ]);
    }

    public function deadStock(Request $request): View|StreamedResponse
    {
        $filters = $this->filters($request);
        $threshold = $request->filled('days') ? max(1, (int) $request->query('days')) : null;
        $result = $this->reports->deadStock($this->companyId($request), $threshold, $filters['warehouse'], $filters['q']);

        if ($this->wantsCsv($request)) {
            return $this->csv('dead-stock', $result['rows'], [
                'SKU', 'Product', 'Warehouse', 'On hand', 'Value', 'Last movement', 'Days idle',
            ], fn ($row) => [
                $row['product']->sku,
                $row['product']->name,
                $row['warehouse']?->name,
                $row['on_hand'],
                $row['value'],
                $row['last_movement_at']?->format('Y-m-d H:i'),
                $row['days_idle'],
            ], $result['totals']);
        }

        return view('inventory.reports.dead-stock', [
            'filters' => $filters,
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'buckets' => $result['buckets'],
            'threshold' => $result['threshold'],
            'warehouses' => $this->reports->warehouses(),
        ]);
    }

    public function stock(Request $request): View|StreamedResponse
    {
        $filters = $this->filters($request);
        $result = $this->reports->stock($this->companyId($request), $filters['warehouse'], $filters['q']);

        if ($this->wantsCsv($request)) {
            return $this->csv('stock-report', $result['rows'], [
                'SKU', 'Product', 'Warehouse', 'Cost method', 'On hand', 'Reserved', 'Available', 'In transit',
                'Damaged', 'Quarantined', 'Value', 'Last movement', 'Days idle',
            ], fn ($row) => [
                $row['product']->sku,
                $row['product']->name,
                $row['warehouse']?->name,
                $row['product']->cost_method,
                $row['on_hand'],
                $row['reserved'],
                $row['available'],
                $row['in_transit'],
                $row['damaged'],
                $row['quarantined'],
                $row['value'],
                $row['last_movement_at']?->format('Y-m-d H:i'),
                $row['days_idle'],
            ], $result['totals']);
        }

        return view('inventory.reports.stock', [
            'filters' => $filters,
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'warehouses' => $this->reports->warehouses(),
        ]);
    }

    /** @return array{warehouse: ?int, q: ?string} */
    protected function filters(Request $request): array
    {
        $search = trim((string) $request->query('q'));

        return [
            'warehouse' => $request->filled('warehouse') ? (int) $request->query('warehouse') : null,
            'q' => $search === '' ? null : $search,
        ];
    }

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    protected function wantsCsv(Request $request): bool
    {
        return strtolower((string) $request->query('format')) === 'csv';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $headings
     * @param  callable(array<string, mixed>): array<int, mixed>  $map
     * @param  array<string, float>  $totals
     */
    protected function csv(string $name, $rows, array $headings, callable $map, array $totals = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headings, $map, $totals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $map($row));
            }

            if ($totals !== []) {
                // A labelled totals block rather than a guess at which column
                // each figure belongs under — the CSV is read by machines too.
                fputcsv($out, []);
                fputcsv($out, ['Totals']);

                foreach ($totals as $label => $value) {
                    fputcsv($out, [str_replace('_', ' ', ucfirst($label)), $value]);
                }
            }

            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
