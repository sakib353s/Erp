<?php

namespace App\Domain\Reporting;

use App\Domain\Sales\Invoice;
use App\Domain\Sales\Promotion;
use App\Domain\Sales\PromotionUsage;
use App\Domain\Sales\SalesTarget;
use Illuminate\Support\Collection;

/**
 * PromotionReport (02-107). Real attributed sales for promotions in a
 * period window. Revenue only from issued|partial|paid invoices linked
 * as promotion usage source. No synthetic BI.
 */
class PromotionReport
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{promotions: int, redemptions: int, discount: float, attributed_revenue: float},
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

        $usages = PromotionUsage::query()
            ->where('company_id', $companyId)
            ->whereDate('used_at', '>=', $bounds['period_start'])
            ->whereDate('used_at', '<=', $bounds['period_end'])
            ->with('promotion')
            ->orderBy('used_at')
            ->get();

        $directInvoiceIds = $usages
            ->filter(fn (PromotionUsage $u) => $u->source_type === 'invoice')
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        $orderIds = $usages
            ->filter(fn (PromotionUsage $u) => $u->source_type === 'sales_order')
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

        $byPromotion = [];
        foreach ($usages as $usage) {
            $promotionId = (int) $usage->promotion_id;
            if (! isset($byPromotion[$promotionId])) {
                $byPromotion[$promotionId] = [
                    'promotion' => $usage->promotion,
                    'redemptions' => 0,
                    'discount' => 0.0,
                    'attributed_revenue' => 0.0,
                ];
            }
            $byPromotion[$promotionId]['redemptions']++;
            $byPromotion[$promotionId]['discount'] = round(
                $byPromotion[$promotionId]['discount'] + (float) $usage->discount_amount,
                4,
            );
            if ($usage->source_type === 'invoice' && isset($revenueByInvoice[$usage->source_id])) {
                $byPromotion[$promotionId]['attributed_revenue'] = round(
                    $byPromotion[$promotionId]['attributed_revenue'] + $revenueByInvoice[$usage->source_id],
                    4,
                );
            } elseif ($usage->source_type === 'sales_order' && isset($revenueByOrder[$usage->source_id])) {
                $byPromotion[$promotionId]['attributed_revenue'] = round(
                    $byPromotion[$promotionId]['attributed_revenue'] + $revenueByOrder[$usage->source_id],
                    4,
                );
            }
        }

        $promotions = Promotion::query()
            ->where('company_id', $companyId)
            ->orderBy('priority', 'desc')
            ->orderBy('name')
            ->get();

        $rows = $promotions->map(function (Promotion $promotion) use ($byPromotion) {
            $agg = $byPromotion[$promotion->id] ?? [
                'redemptions' => 0,
                'discount' => 0.0,
                'attributed_revenue' => 0.0,
            ];

            return [
                'promotion' => $promotion,
                'redemptions' => $agg['redemptions'],
                'discount' => round($agg['discount'], 4),
                'attributed_revenue' => round($agg['attributed_revenue'], 4),
            ];
        })->values();

        $used = $rows->filter(fn (array $r) => $r['redemptions'] > 0);

        return [
            'rows' => $rows,
            'totals' => [
                'promotions' => $rows->count(),
                'redemptions' => (int) $rows->sum('redemptions'),
                'discount' => round((float) $rows->sum('discount'), 4),
                'attributed_revenue' => round((float) $rows->sum('attributed_revenue'), 4),
            ],
            'bounds' => $bounds,
            'period_type' => $periodType,
            'method' => 'COUNT/SUM(promotion_usages) in period; attributed revenue = SUM(invoices.grand_total) for source_type=invoice, or invoice.sales_order_id join when source_type=sales_order, where status ∈ issued|partial|paid',
            'sample_size' => $used->count(),
        ];
    }
}
