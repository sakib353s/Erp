<?php

namespace App\Domain\Sales\Queries;

use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side queries over sales orders (02-28 fake/suspicious view).
 */
class OrderQuery
{
    /**
     * Orders that carry a suspicion flag for the company, strongest
     * first, with the flag (score + explainability) eager-loaded for the
     * list screen. The join is inner on purpose: unflagged orders are
     * not part of this view at all.
     */
    public function suspicious(Builder $query, int $companyId): Builder
    {
        return $query
            ->select('sales_orders.*')
            ->join('suspicious_order_flags', 'suspicious_order_flags.sales_order_id', '=', 'sales_orders.id')
            ->where('suspicious_order_flags.company_id', $companyId)
            ->where('sales_orders.company_id', $companyId)
            ->with(['customer', 'suspiciousFlag'])
            ->orderByDesc('suspicious_order_flags.score')
            ->orderByDesc('sales_orders.id');
    }
}
