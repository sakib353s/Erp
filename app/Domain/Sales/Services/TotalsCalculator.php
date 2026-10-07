<?php

namespace App\Domain\Sales\Services;

use App\Domain\Masters\Services\TaxService;

/**
 * Server-authoritative line + document totals (02-29 OrderTotalsServerAuthoritative).
 * Controllers never compute grand totals — they call this and persist the result.
 *
 * Tax is applied per-line on (line_total − line_discount) when tax_applicable,
 * using TaxService (effective-dated). Document-level doc_discount reduces
 * taxable_base before tax aggregation is re-derived from lines.
 */
class TotalsCalculator
{
    public function __construct(protected TaxService $tax) {}

    /**
     * @param array<int, array{
     *   qty: float|int|string,
     *   unit_price: float|int|string,
     *   discount?: float|int|string,
     *   tax_code?: string|null,
     * }> $lines
     * @return array{
     *   lines: array<int, array{qty: float, unit_price: float, discount: float, taxable: float, tax: float, line_total: float}>,
     *   subtotal: float,
     *   line_discount_total: float,
     *   doc_discount: float,
     *   taxable_base: float,
     *   tax: float,
     *   shipping: float,
     *   rounding: float,
     *   grand_total: float,
     * }
     */
    public function calculate(
        array $lines,
        float $docDiscount = 0.0,
        float $shipping = 0.0,
        ?string $taxCode = null,
        ?string $at = null,
        bool $taxApplicable = false,
    ): array {
        $computed = [];
        $subtotal = 0.0;
        $lineDiscountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $i => $line) {
            $qty = (float) ($line['qty'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $gross = round($qty * $unit, 4);
            $lineDiscountTotal += $discount;
            $net = round(max(0, $gross - $discount), 4);

            $lineTax = 0.0;
            if ($taxApplicable && $taxCode !== null && $taxCode !== '') {
                $lineTax = $this->tax->apply($net, $taxCode, $at);
            }

            $lineTotal = round($net + $lineTax, 4);

            $computed[$i] = [
                'qty' => $qty,
                'unit_price' => round($unit, 4),
                'discount' => round($discount, 4),
                'taxable' => $net,
                'tax' => $lineTax,
                'line_total' => $lineTotal,
            ];

            $subtotal = round($subtotal + $gross, 4);
            $taxTotal = round($taxTotal + $lineTax, 4);
        }

        $docDiscount = round(max(0, $docDiscount), 4);
        $taxableBase = round(max(0, $subtotal - $lineDiscountTotal - $docDiscount), 4);
        $shipping = round(max(0, $shipping), 4);

        // Grand total: net lines (already include tax) − doc_discount + shipping
        // Recompute from line nets for consistency:
        $linesNet = 0.0;
        foreach ($computed as $c) {
            $linesNet = round($linesNet + $c['taxable'], 4);
        }
        $linesNet = round(max(0, $linesNet - $docDiscount), 4);
        $grandRaw = round($linesNet + $taxTotal + $shipping, 4);

        // Round to 2 decimal places (BDT) with rounding adjustment column
        $grand = round($grandRaw, 2);
        $rounding = round($grand - $grandRaw, 4);

        return [
            'lines' => $computed,
            'subtotal' => $subtotal,
            'line_discount_total' => round($lineDiscountTotal, 4),
            'doc_discount' => $docDiscount,
            'taxable_base' => $taxableBase,
            'tax' => $taxTotal,
            'shipping' => $shipping,
            'rounding' => $rounding,
            'grand_total' => $grand,
        ];
    }
}
