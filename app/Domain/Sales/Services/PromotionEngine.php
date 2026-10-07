<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Promotion;

/**
 * PromotionEngine (02-105/02-106). Picks the highest-priority active
 * promotion for a cart within window and branch scope. Flash sales are
 * ordinary promotions with a required time window (countdown reads DB
 * timestamps only — never a client fake timer).
 */
class PromotionEngine
{
    /**
     * @param array<int, array{
     *   product_id?: int|null,
     *   qty?: float|int|string,
     *   unit_price?: float|int|string,
     *   discount?: float|int|string
     * }> $lines
     * @return array{promotion: Promotion|null, discount: float}
     */
    public function bestForCart(
        int $companyId,
        ?int $branchId,
        array $lines,
        float $netSubtotal,
        ?string $at = null,
    ): array {
        $at = $at ?? now()->toDateTimeString();
        $netSubtotal = max(0.0, round($netSubtotal, 4));

        if ($netSubtotal <= 0) {
            return ['promotion' => null, 'discount' => 0.0];
        }

        $candidates = Promotion::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId !== null) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->activeAt($at)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->with('items')
            ->get();

        foreach ($candidates as $promotion) {
            if ($promotion->min_subtotal !== null
                && $netSubtotal + 1e-9 < (float) $promotion->min_subtotal) {
                continue;
            }

            $discount = $this->discountFor($promotion, $lines, $netSubtotal);
            if ($discount > 1e-9) {
                return ['promotion' => $promotion, 'discount' => round(min($netSubtotal, $discount), 4)];
            }
        }

        return ['promotion' => null, 'discount' => 0.0];
    }

    /**
     * @param  array<int, array{product_id?: int|null, qty?: float|int|string, unit_price?: float|int|string, discount?: float|int|string}>  $lines
     */
    protected function discountFor(Promotion $promotion, array $lines, float $netSubtotal): float
    {
        // Cart-level when no product items bound
        if ($promotion->items->isEmpty()) {
            return match ($promotion->type) {
                'percent_off' => round($netSubtotal * ((float) $promotion->value) / 100, 4),
                'fixed_off' => round(min($netSubtotal, (float) $promotion->value), 4),
                default => 0.0,
            };
        }

        $eligibleIds = $promotion->items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $eligibleNet = 0.0;
        foreach ($lines as $line) {
            $productId = isset($line['product_id']) && $line['product_id'] !== null
                ? (int) $line['product_id']
                : null;
            if ($productId === null || ! in_array($productId, $eligibleIds, true)) {
                continue;
            }
            $qty = (float) ($line['qty'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $lineDiscount = (float) ($line['discount'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $eligibleNet += round(max(0, $qty * $unit - $lineDiscount), 4);
        }

        if ($eligibleNet <= 0) {
            return 0.0;
        }

        return match ($promotion->type) {
            'percent_off' => round($eligibleNet * ((float) $promotion->value) / 100, 4),
            'fixed_off' => round(min($eligibleNet, (float) $promotion->value), 4),
            default => 0.0,
        };
    }
}
