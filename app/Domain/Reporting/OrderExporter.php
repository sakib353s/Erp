<?php

namespace App\Domain\Reporting;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Reporting\Jobs\GenerateOrderExport;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;

/**
 * OrderExporter (02-13): creates a queued, scope-filtered export and
 * builds its CSV. Scope = the export's company + the requesting user's
 * branch visibility (accessibleBranchIds; null = all branches inside
 * the company). Filters (status/search) mirror the orders screen and
 * are validated inputs only — never SQL.
 */
class OrderExporter
{
    public function handle(Request $request): OrderExport
    {
        $companyId = app(TenantContext::class)->companyId()
            ?? abort(500, 'No company context.');

        $filters = array_filter([
            'status' => $request->input('status'),
            'q' => trim((string) $request->input('q')),
        ], fn ($value) => $value !== null && $value !== '');

        $export = OrderExport::query()->create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'status' => OrderExport::STATUS_QUEUED,
            'filters' => $filters,
        ]);

        GenerateOrderExport::dispatch($export->id);

        return $export->refresh();
    }

    /** @return array{0: string, 1: int} CSV bytes + row count */
    public function buildCsv(OrderExport $export): array
    {
        $filters = $export->filters ?? [];

        $query = SalesOrder::query()
            ->with('customer')
            ->where('company_id', $export->company_id)
            ->orderBy('id');

        $branchIds = $export->user->accessibleBranchIds();
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['q'])) {
            $query->where('order_no', 'like', '%'.$filters['q'].'%');
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [
            'order_no', 'order_date', 'status', 'customer',
            'branch_id', 'subtotal', 'grand_total', 'currency',
        ]);

        $rowCount = 0;
        $query->chunk(500, function ($orders) use ($stream, &$rowCount) {
            foreach ($orders as $order) {
                fputcsv($stream, [
                    $order->order_no,
                    optional($order->order_date)->toDateString(),
                    $order->status,
                    $order->customer?->name ?? '',
                    $order->branch_id,
                    $order->subtotal,
                    $order->grand_total,
                    $order->currency,
                ]);
                $rowCount++;
            }
        });

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return [$csv, $rowCount];
    }
}
