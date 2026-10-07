<?php

namespace App\Domain\Reporting;

use App\Domain\Sales\Invoice;
use App\Domain\Sales\InvoiceLine;
use App\Domain\Sales\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SalesBreakdownReport (02-115 / 02-116 / 02-117): revenue splits for a
 * date window.
 *
 * Line dims (product, category, brand) aggregate real invoice_lines of
 * issued/partial/paid invoices — qty and line net (qty × price − discount,
 * excluding tax). Invoice dims (customer, employee, branch, zone) aggregate
 * the invoices themselves (count, gross, paid, due); the branch dimension
 * is restricted to the caller's accessible branches when scoped, and the
 * zone dimension maps each customer's district through delivery_zone_district.
 * The payment dim (method) aggregates posted inbound receipts.
 */
class SalesBreakdownReport
{
    public const DIMS = ['product', 'category', 'brand', 'customer', 'employee', 'branch', 'zone', 'method'];

    public const LINE_DIMS = ['product', 'category', 'brand'];

    public const INVOICE_DIMS = ['customer', 'employee', 'branch', 'zone'];

    public const PAYMENT_DIMS = ['method'];

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, branch_ids?: array<int, int>|null}  $filters
     * @return array{
     *   dim: string,
     *   source: string,
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array<string, int|float>,
     *   filters: array{date_from: string, date_to: string},
     * }
     */
    public function forCompany(int $companyId, string $dim, array $filters = []): array
    {
        if (! in_array($dim, self::DIMS, true)) {
            throw new \InvalidArgumentException("Unknown breakdown dimension [{$dim}].");
        }

        $dateFrom = ($filters['date_from'] ?? null) ?: now()->startOfMonth()->toDateString();
        $dateTo = ($filters['date_to'] ?? null) ?: now()->toDateString();

        if (in_array($dim, self::LINE_DIMS, true)) {
            return $this->byLines($companyId, $dim, $dateFrom, $dateTo);
        }

        if (in_array($dim, self::PAYMENT_DIMS, true)) {
            return $this->byPayments($companyId, $dateFrom, $dateTo);
        }

        return $this->byInvoices($companyId, $dim, $dateFrom, $dateTo, $filters['branch_ids'] ?? null);
    }

    /**
     * @return array{
     *   dim: string,
     *   source: string,
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{rows: int, qty: float, net: float, invoices: int},
     *   filters: array{date_from: string, date_to: string},
     * }
     */
    protected function byLines(int $companyId, string $dim, string $dateFrom, string $dateTo): array
    {
        $base = fn (): Builder => $this->linesQuery($companyId, $dateFrom, $dateTo, $dim);

        $agg = $base()
            ->selectRaw('count(distinct invoice_lines.invoice_id) as invoices')
            ->selectRaw('coalesce(sum(invoice_lines.qty), 0) as qty')
            ->selectRaw('coalesce(sum(invoice_lines.qty * invoice_lines.unit_price - invoice_lines.discount), 0) as net')
            ->toBase()
            ->first();

        $group = match ($dim) {
            'product' => ['invoice_lines.product_id', 'products.sku', 'products.name'],
            'category' => ['products.product_category_id', 'product_categories.name'],
            'brand' => ['products.brand_id', 'brands.name'],
        };

        $select = match ($dim) {
            'product' => [
                'invoice_lines.product_id as key',
                'products.sku as code',
                'products.name as label',
            ],
            'category' => [
                'products.product_category_id as key',
                'null as code',
                'product_categories.name as label',
            ],
            'brand' => [
                'products.brand_id as key',
                'null as code',
                'brands.name as label',
            ],
        };

        $rows = $base()
            ->selectRaw(implode(', ', $select))
            ->selectRaw('count(distinct invoice_lines.invoice_id) as invoices')
            ->selectRaw('count(*) as lines')
            ->selectRaw('coalesce(sum(invoice_lines.qty), 0) as qty')
            ->selectRaw('coalesce(sum(invoice_lines.qty * invoice_lines.unit_price - invoice_lines.discount), 0) as net')
            ->groupBy($group)
            ->orderByDesc('net')
            ->get()
            ->map(function (object $row) use ($dim): array {
                $net = round((float) $row->net, 4);

                return [
                    'key' => $row->key,
                    'code' => $row->code,
                    'label' => $row->label
                        ?? match ($dim) {
                            'category' => 'Uncategorised',
                            'brand' => 'No brand',
                            default => 'No product',
                        },
                    'qty' => round((float) $row->qty, 4),
                    'invoices' => (int) $row->invoices,
                    'lines' => (int) $row->lines,
                    'net' => $net,
                ];
            });

        $netTotal = round((float) ($agg->net ?? 0), 4);

        $rows = $rows->map(function (array $row) use ($netTotal): array {
            $row['share'] = $netTotal > 0
                ? round($row['net'] / $netTotal * 100, 2)
                : 0.0;

            return $row;
        });

        return [
            'dim' => $dim,
            'source' => 'lines',
            'rows' => $rows,
            'totals' => [
                'rows' => $rows->count(),
                'qty' => round((float) ($agg->qty ?? 0), 4),
                'net' => $netTotal,
                'invoices' => (int) ($agg->invoices ?? 0),
            ],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ];
    }

    /**
     * @param  array<int, int>|null  $branchIds  null = unrestricted; [] = nothing visible
     * @return array{
     *   dim: string,
     *   source: string,
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{rows: int, invoices: int, gross: float, paid: float, due: float},
     *   filters: array{date_from: string, date_to: string},
     * }
     */
    protected function byInvoices(
        int $companyId,
        string $dim,
        string $dateFrom,
        string $dateTo,
        ?array $branchIds,
    ): array {
        $base = function () use ($companyId, $dim, $dateFrom, $dateTo, $branchIds): Builder {
            $query = Invoice::query()
                ->where('invoices.company_id', $companyId)
                ->whereIn('invoices.status', ['issued', 'partial', 'paid'])
                ->whereNotIn('invoices.invoice_type', ['layaway']) // deposits are liabilities, not revenue
                ->whereDate('invoices.invoice_date', '>=', $dateFrom)
                ->whereDate('invoices.invoice_date', '<=', $dateTo);

            if ($dim === 'branch') {
                $query->leftJoin('branches', 'branches.id', '=', 'invoices.branch_id');

                if ($branchIds !== null) {
                    $query->whereIn('invoices.branch_id', $branchIds);
                }
            } elseif ($dim === 'customer') {
                $query->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id');
            } elseif ($dim === 'zone') {
                // Customer district → delivery zone (a district in several
                // zones claims the lowest zone id, so nothing double counts).
                $query->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                    ->leftJoinSub(
                        DB::table('delivery_zone_district')
                            ->select('district_id', DB::raw('min(delivery_zone_id) as zone_id'))
                            ->groupBy('district_id'),
                        'dzmap',
                        'dzmap.district_id',
                        '=',
                        'customers.district_id',
                    )
                    ->leftJoin('delivery_zones', 'delivery_zones.id', '=', 'dzmap.zone_id');
            } else {
                $query->leftJoin('employees', 'employees.id', '=', 'invoices.sales_person_id');
            }

            return $query;
        };

        $agg = $base()
            ->selectRaw('count(*) as invoices')
            ->selectRaw('coalesce(sum(grand_total), 0) as gross')
            ->selectRaw('coalesce(sum(paid_amount), 0) as paid')
            ->selectRaw('coalesce(sum(due_amount), 0) as due')
            ->toBase()
            ->first();

        $select = match ($dim) {
            'customer' => [
                'invoices.customer_id as key',
                'null as code',
                'customers.name as label',
            ],
            'employee' => [
                'invoices.sales_person_id as key',
                'null as code',
                'employees.full_name as label',
            ],
            'branch' => [
                'invoices.branch_id as key',
                'null as code',
                'branches.name as label',
            ],
            'zone' => [
                'dzmap.zone_id as key',
                'null as code',
                'delivery_zones.name as label',
            ],
        };

        $group = match ($dim) {
            'customer' => ['invoices.customer_id', 'customers.name'],
            'employee' => ['invoices.sales_person_id', 'employees.full_name'],
            'branch' => ['invoices.branch_id', 'branches.name'],
            'zone' => ['dzmap.zone_id', 'delivery_zones.name'],
        };

        $fallback = match ($dim) {
            'customer' => 'No customer',
            'employee' => 'Unassigned',
            'branch' => 'No branch',
            'zone' => 'No zone',
        };

        $rows = $base()
            ->selectRaw(implode(', ', $select))
            ->selectRaw('count(*) as invoices')
            ->selectRaw('coalesce(sum(grand_total), 0) as gross')
            ->selectRaw('coalesce(sum(paid_amount), 0) as paid')
            ->selectRaw('coalesce(sum(due_amount), 0) as due')
            ->groupBy($group)
            ->orderByDesc('gross')
            ->get()
            ->map(fn (object $row): array => [
                'key' => $row->key,
                'code' => $row->code,
                'label' => $row->label ?? $fallback,
                'invoices' => (int) $row->invoices,
                'gross' => round((float) $row->gross, 4),
                'paid' => round((float) $row->paid, 4),
                'due' => round((float) $row->due, 4),
            ]);

        $grossTotal = round((float) ($agg->gross ?? 0), 4);

        $rows = $rows->map(function (array $row) use ($grossTotal): array {
            $row['share'] = $grossTotal > 0
                ? round($row['gross'] / $grossTotal * 100, 2)
                : 0.0;

            return $row;
        });

        return [
            'dim' => $dim,
            'source' => 'invoices',
            'rows' => $rows,
            'totals' => [
                'rows' => $rows->count(),
                'invoices' => (int) ($agg->invoices ?? 0),
                'gross' => $grossTotal,
                'paid' => round((float) ($agg->paid ?? 0), 4),
                'due' => round((float) ($agg->due ?? 0), 4),
            ],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ];
    }

    /**
     * 02-117: posted inbound receipts grouped by payment method (cash/bank/cheque/mobile).
     *
     * @return array{
     *   dim: string,
     *   source: string,
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{rows: int, payments: int, amount: float},
     *   filters: array{date_from: string, date_to: string},
     * }
     */
    protected function byPayments(int $companyId, string $dateFrom, string $dateTo): array
    {
        $base = fn (): Builder => Payment::query()
            ->where('company_id', $companyId)
            ->where('direction', 'in')
            ->where('status', 'posted')
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $agg = $base()
            ->selectRaw('count(*) as payments')
            ->selectRaw('coalesce(sum(amount), 0) as amount')
            ->toBase()
            ->first();

        $amountTotal = round((float) ($agg->amount ?? 0), 4);

        $rows = $base()
            ->selectRaw('method')
            ->selectRaw('count(*) as payments')
            ->selectRaw('coalesce(sum(amount), 0) as amount')
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row): array => [
                'key' => $row->method,
                'code' => null,
                'label' => ucfirst((string) $row->method),
                'payments' => (int) $row->payments,
                'amount' => round((float) $row->amount, 4),
            ]);

        $rows = $rows->map(function (array $row) use ($amountTotal): array {
            $row['share'] = $amountTotal > 0
                ? round($row['amount'] / $amountTotal * 100, 2)
                : 0.0;

            return $row;
        });

        return [
            'dim' => 'method',
            'source' => 'payments',
            'rows' => $rows,
            'totals' => [
                'rows' => $rows->count(),
                'payments' => (int) ($agg->payments ?? 0),
                'amount' => $amountTotal,
            ],
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ];
    }

    protected function linesQuery(int $companyId, string $dateFrom, string $dateTo, string $dim): Builder
    {
        $query = InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_lines.product_id')
            ->where('invoice_lines.company_id', $companyId)
            ->where('invoices.company_id', $companyId)
            ->whereIn('invoices.status', ['issued', 'partial', 'paid'])
            ->whereDate('invoices.invoice_date', '>=', $dateFrom)
            ->whereDate('invoices.invoice_date', '<=', $dateTo);

        if ($dim === 'category') {
            $query->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id');
        }
        if ($dim === 'brand') {
            $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id');
        }

        return $query;
    }
}
