<?php

namespace App\Domain\Reporting;

use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use Illuminate\Support\Collection;

/**
 * SalesSummaryReport (02-114): server-side sales totals over a filterable
 * window. Every figure is a direct aggregate of invoices and payments —
 * no modelled or synthetic BI.
 */
class SalesSummaryReport
{
    /**
     * @param  array{date_from?:?string, date_to?:?string, branch_id?:?int, customer_id?:?int, status?:?string, method?:?string}  $filters
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   by_status: array<string, array{invoices: int, gross: float, paid: float, due: float}>,
     *   totals: array{invoices: int, subtotal: float, discount: float, tax: float, gross: float, paid: float, due: float, collections: float},
     *   payments: array{count: int, amount: float},
     *   filters: array<string, mixed>,
     * }
     */
    public function forCompany(int $companyId, array $filters = []): array
    {
        $dateFrom = ($filters['date_from'] ?? null) ?: now()->startOfMonth()->toDateString();
        $dateTo = ($filters['date_to'] ?? null) ?: now()->toDateString();

        $invoiceQuery = Invoice::query()
            ->where('company_id', $companyId)
            ->whereNotIn('invoice_type', ['layaway']) // deposits are liabilities, not revenue
            ->whereDate('invoice_date', '>=', $dateFrom)
            ->whereDate('invoice_date', '<=', $dateTo);

        if (! empty($filters['branch_id'])) {
            $invoiceQuery->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['customer_id'])) {
            $invoiceQuery->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['status'])) {
            $invoiceQuery->where('status', $filters['status']);
        }

        $invoices = $invoiceQuery->with('customer')->orderBy('invoice_date')->orderBy('id')->get();

        $rows = $invoices->map(fn (Invoice $invoice): array => [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'customer' => $invoice->customer?->name,
            'status' => $invoice->status,
            'gross' => round((float) $invoice->grand_total, 4),
            'paid' => round((float) $invoice->paid_amount, 4),
            'due' => round((float) $invoice->due_amount, 4),
        ]);

        $byStatus = [];
        foreach ($invoices as $invoice) {
            $status = $invoice->status;
            $byStatus[$status] ??= ['invoices' => 0, 'gross' => 0.0, 'paid' => 0.0, 'due' => 0.0];
            $byStatus[$status]['invoices']++;
            $byStatus[$status]['gross'] = round($byStatus[$status]['gross'] + (float) $invoice->grand_total, 4);
            $byStatus[$status]['paid'] = round($byStatus[$status]['paid'] + (float) $invoice->paid_amount, 4);
            $byStatus[$status]['due'] = round($byStatus[$status]['due'] + (float) $invoice->due_amount, 4);
        }

        $paymentQuery = Payment::query()
            ->where('company_id', $companyId)
            ->where('direction', 'in')
            ->where('status', 'posted')
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        if (! empty($filters['branch_id'])) {
            $paymentQuery->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['customer_id'])) {
            $paymentQuery->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['method'])) {
            $paymentQuery->where('method', $filters['method']);
        }

        $collections = (float) $paymentQuery->sum('amount');
        $paymentCount = (int) $paymentQuery->count();

        return [
            'rows' => $rows,
            'by_status' => $byStatus,
            'totals' => [
                'invoices' => $rows->count(),
                'subtotal' => round((float) $invoices->sum('subtotal'), 4),
                'discount' => round(
                    (float) $invoices->sum('doc_discount') + (float) $invoices->sum('coupon_discount'),
                    4,
                ),
                'tax' => round((float) $invoices->sum('tax'), 4),
                'gross' => round((float) $invoices->sum('grand_total'), 4),
                'paid' => round((float) $invoices->sum('paid_amount'), 4),
                'due' => round((float) $invoices->sum('due_amount'), 4),
                'collections' => round($collections, 4),
            ],
            'payments' => ['count' => $paymentCount, 'amount' => round($collections, 4)],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'branch_id' => $filters['branch_id'] ?? null,
                'customer_id' => $filters['customer_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'method' => $filters['method'] ?? null,
            ],
        ];
    }
}
