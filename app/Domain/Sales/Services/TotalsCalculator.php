<?php

namespace App\Domain\Sales\Services;

use App\Domain\Masters\Services\TaxService;
use App\Domain\Tax\Services\TaxPolicy;

/**
 * Server-authoritative line + document totals (02-29 OrderTotalsServerAuthoritative).
 * Controllers never compute grand totals — they call this and persist the result.
 *
 * Tax is applied per-line on (line_total − line_discount) when tax_applicable,
 * using TaxService (effective-dated) through {@see TaxPolicy} (§15-14), which is
 * what decides whether the price already includes VAT, where the rounding
 * happens and which rate an untagged sale uses. Document-level doc_discount
 * reduces taxable_base before tax aggregation is re-derived from lines.
 *
 * With the shipped defaults (exclusive pricing, rounded once on the document)
 * the arithmetic is the arithmetic this class has always done — the policy
 * changes it only when a company has actually chosen something else.
 */
class TotalsCalculator
{
    public function __construct(
        protected TaxService $tax,
        protected TaxPolicy $policy,
    ) {}

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

            /*
             * §15-14: the policy decides the money. An untagged taxable sale may
             * pick up the company's default code; inclusive pricing moves part
             * of the entered figure from the taxable value into the tax instead
             * of charging on top of it.
             */
            $lineTax = 0.0;
            if ($taxApplicable) {
                $policyLine = $this->policy->line($net, $taxCode, $at);
                $net = $policyLine['net'];
                $lineTax = $this->policy->storedLineTax($policyLine['tax']);
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
        }

        $docDiscount = round(max(0, $docDiscount), 4);
        $shipping = round(max(0, $shipping), 4);

        // The taxable value is the sum of what the lines actually hold as
        // taxable, less the document discount — which is the same figure the
        // old gross-minus-discounts formula produced under exclusive pricing,
        // and the only correct one when the price already included the tax.
        $linesTaxable = 0.0;
        foreach ($computed as $c) {
            $linesTaxable = round($linesTaxable + $c['taxable'], 4);
        }

        $taxableBase = round(max(0, $linesTaxable - $docDiscount), 4);

        // Grand total: net lines (already include tax) − doc_discount + shipping
        // Recompute from line nets for consistency:
        $linesNet = round(max(0, $linesTaxable - $docDiscount), 4);

        // Per-line tax is stored rounded when the policy rounds per line, so the
        // document's tax has to be summed from the stored figures — otherwise
        // the invoice would print a tax its own lines do not add up to.
        $taxTotal = $this->policy->documentTax(array_map(
            fn (array $line): float => (float) $line['tax'],
            $computed,
        ));

        $grandRaw = round($linesNet + $taxTotal + $shipping, 4);

        // Round to the currency (BDT) with the rounding adjustment column —
        // two decimals, or the nearest taka when the company asked for it.
        $rounded = $this->policy->roundTotal($grandRaw);
        $grand = $rounded['total'];
        $rounding = $rounded['rounding'];

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
