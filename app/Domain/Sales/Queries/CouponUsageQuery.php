<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesTarget;
use Illuminate\Support\Collection;

/**
 * CouponUsageQuery (02-103). Real redemption counts and attributed
 * revenue only — invoices linked as coupon usage source with status
 * issued|partial|paid. No estimate or fake BI.
 */
class CouponUsageQuery
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{redemptions: int, discount: float, attributed_revenue: float, coupons_used: int},
     *   bounds: array{period_start: string, period_end: string},
     *   period_type: string,
     *   method: string,
     *   sample_size: int,
     * }
     */
    public function forPeriod(int $companyId, string $periodType, ?string $at = null): array
    {
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            $periodType = 'monthly';
        }

        $bounds = SalesTarget::boundsFor($periodType, $at);

        $usages = CouponUsage::query()
            ->where('company_id', $companyId)
            ->whereDate('used_at', '>=', $bounds['period_start'])
            ->whereDate('used_at', '<=', $bounds['period_end'])
            ->with(['coupon', 'customer'])
            ->orderBy('used_at')
            ->get();

        $directInvoiceIds = $usages
            ->filter(fn (CouponUsage $u) => $u->source_type === 'invoice')
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        $orderIds = $usages
            ->filter(fn (CouponUsage $u) => $u->source_type === 'sales_order')
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        $revenueByInvoice = [];
        if ($directInvoiceIds !== []) {
            $revenueByInvoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $directInvoiceIds)
                ->whereIn('status', ['issued', 'partial', 'paid'])
                ->pluck('grand_total', 'id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $revenueByOrder = [];
        if ($orderIds !== []) {
            $revenueByOrder = Invoice::query()
                ->where('company_id', $companyId)
                ->whereIn('sales_order_id', $orderIds)
                ->whereIn('status', ['issued', 'partial', 'paid'])
                ->pluck('grand_total', 'sales_order_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $byCoupon = [];
        foreach ($usages as $usage) {
            $couponId = (int) $usage->coupon_id;
            if (! isset($byCoupon[$couponId])) {
                $byCoupon[$couponId] = [
                    'coupon' => $usage->coupon,
                    'redemptions' => 0,
                    'discount' => 0.0,
                    'attributed_revenue' => 0.0,
                ];
            }
            $byCoupon[$couponId]['redemptions']++;
            $byCoupon[$couponId]['discount'] = round(
                $byCoupon[$couponId]['discount'] + (float) $usage->discount_amount,
                4,
            );
            if ($usage->source_type === 'invoice' && isset($revenueByInvoice[$usage->source_id])) {
                $byCoupon[$couponId]['attributed_revenue'] = round(
                    $byCoupon[$couponId]['attributed_revenue'] + $revenueByInvoice[$usage->source_id],
                    4,
                );
            } elseif ($usage->source_type === 'sales_order' && isset($revenueByOrder[$usage->source_id])) {
                $byCoupon[$couponId]['attributed_revenue'] = round(
                    $byCoupon[$couponId]['attributed_revenue'] + $revenueByOrder[$usage->source_id],
                    4,
                );
            }
        }

        // Include zero-use coupons only for analytics overview of active catalog
        $activeCoupons = Coupon::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get();

        $rows = $activeCoupons->map(function (Coupon $coupon) use ($byCoupon) {
            $agg = $byCoupon[$coupon->id] ?? [
                'redemptions' => 0,
                'discount' => 0.0,
                'attributed_revenue' => 0.0,
            ];

            return [
                'coupon' => $coupon,
                'redemptions' => $agg['redemptions'],
                'discount' => round($agg['discount'], 4),
                'attributed_revenue' => round($agg['attributed_revenue'], 4),
            ];
        })->values();

        $usedInWindow = $rows->filter(fn (array $r) => $r['redemptions'] > 0);

        return [
            'rows' => $rows,
            'totals' => [
                'redemptions' => (int) $rows->sum('redemptions'),
                'discount' => round((float) $rows->sum('discount'), 4),
                'attributed_revenue' => round((float) $rows->sum('attributed_revenue'), 4),
                'coupons_used' => $usedInWindow->count(),
            ],
            'bounds' => $bounds,
            'period_type' => $periodType,
            'method' => 'COUNT/SUM(coupon_usages) in period; attributed revenue = SUM(invoices.grand_total) for source_type=invoice, or invoice.sales_order_id join when source_type=sales_order, where status ∈ issued|partial|paid',
            'sample_size' => $usedInWindow->count(),
        ];
    }

    /**
     * Analytics view: top coupons by attributed revenue within period.
     *
     * @return array<string, mixed>
     */
    public function analytics(int $companyId, string $periodType, ?string $at = null): array
    {
        $report = $this->forPeriod($companyId, $periodType, $at);

        $top = $report['rows']
            ->filter(fn (array $r) => $r['redemptions'] > 0)
            ->sortByDesc('attributed_revenue')
            ->values();

        return [
            'report' => $report,
            'top_by_revenue' => $top,
            'top_by_redemptions' => $report['rows']
                ->filter(fn (array $r) => $r['redemptions'] > 0)
                ->sortByDesc('redemptions')
                ->values(),
            'method' => $report['method'],
        ];
    }
}
