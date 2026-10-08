<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\PackagingService;
use App\Domain\Inventory\Services\DamageService;
use App\Domain\Inventory\StockDamageEntry;
use App\Domain\Inventory\StockMovement;
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
    public function __construct(
        protected StockReportService $reports,
        protected DamageService $damage,
        protected PackagingService $packaging,
    ) {}

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

    /**
     * Damage & loss analytics (§04-51). Every figure is a sum of documents that
     * exist in the period; the holdings block shows what is still sitting in the
     * damaged compartment, valued from the layers.
     */
    public function damage(Request $request): View|StreamedResponse
    {
        $from = $request->filled('from') ? (string) $request->query('from') : null;
        $to = $request->filled('to') ? (string) $request->query('to') : null;
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $kind = in_array($request->query('kind'), ['damage', 'loss'], true) ? (string) $request->query('kind') : null;
        $reason = $request->filled('reason') ? (string) $request->query('reason') : null;

        $analytics = $this->damage->analytics($from, $to, $warehouseId);
        $entries = $this->damage->entries([
            'kind' => $kind,
            'warehouse' => $warehouseId,
            'reason_code' => $reason,
            'from' => $analytics['from'],
            'to' => $analytics['to'],
        ], 25);

        if ($this->wantsCsv($request)) {
            return $this->csv('damage-loss', $entries->getCollection(), [
                'Code', 'Kind', 'Date', 'Warehouse', 'Cause', 'Reason', 'Status', 'Lines', 'Value',
            ], fn ($entry) => [
                $entry->code,
                $entry->kind,
                $entry->entry_date?->format('Y-m-d'),
                $entry->warehouse?->name,
                $entry->reasonLabel(),
                $entry->reason,
                $entry->status,
                $entry->lines->count(),
                (float) $entry->total_value,
            ], [
                'Entries' => $analytics['totals']['entries'],
                // The headline the screen leads with, first in the totals block:
                // an export missing the figure the page is built around is a
                // different report.
                'Recorded value' => $analytics['totals']['recorded_value'],
                'Damage value' => $analytics['by_kind']['damage']['value'],
                'Loss value' => $analytics['by_kind']['loss']['value'],
                'Written off (approved)' => $analytics['totals']['written_off_value'],
                'Held as damaged' => $analytics['held']['damaged'],
                'Held as damaged, value' => $analytics['held']['damaged_value'],
                'Held as quarantined' => $analytics['held']['quarantined'],
            ]);
        }

        return view('inventory.reports.damage', [
            'analytics' => $analytics,
            'entries' => $entries,
            'filters' => [
                'from' => $analytics['from'],
                'to' => $analytics['to'],
                'warehouse' => $warehouseId,
                'kind' => $kind,
                'reason' => $reason,
            ],
            'reasons' => StockDamageEntry::DAMAGE_REASONS + StockDamageEntry::LOSS_REASONS,
            'holdings' => $this->damage->compartmentHoldings(StockMovement::STATE_DAMAGED, $warehouseId),
            'warehouses' => $this->reports->warehouses(),
        ]);
    }

    /**
     * Packaging consumption by month and type (§04-62). Money that leaves the
     * business in cardboard is still money, and the question is always the same
     * one: did this month cost more because we shipped more, or because a box
     * got dearer? Both answers are on the row.
     */
    public function packaging(Request $request): View|StreamedResponse
    {
        $from = $request->filled('from') ? (string) $request->query('from') : now()->subMonths(3)->startOfMonth()->toDateString();
        $to = $request->filled('to') ? (string) $request->query('to') : now()->toDateString();
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));

        $result = $this->packaging->report($from, $to, $warehouseId, $search === '' ? null : $search);

        if ($this->wantsCsv($request)) {
            return $this->csv('packaging-cost', $result['rows'], [
                'Month', 'Code', 'Packaging', 'Quantity', 'Cost', 'Average unit cost', 'Orders packed',
            ], fn ($row) => [
                $row['period'],
                $row['type']?->code,
                $row['type']?->name,
                $row['qty'],
                $row['cost'],
                $row['unit_cost'],
                $row['orders'],
            ], [
                'Quantity' => $result['totals']['qty'],
                'Cost' => $result['totals']['cost'],
                'Orders packed' => $result['totals']['orders'],
                'Types used' => $result['totals']['types'],
                'Months' => $result['totals']['months'],
            ]);
        }

        return view('inventory.reports.packaging', [
            'rows' => $result['rows'],
            'months' => $result['months'],
            'totals' => $result['totals'],
            'filters' => ['from' => $from, 'to' => $to, 'warehouse' => $warehouseId, 'q' => $search],
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
