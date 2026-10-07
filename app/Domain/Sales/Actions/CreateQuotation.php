<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\QuotationLine;
use App\Domain\Sales\Services\CouponCalculator;
use App\Domain\Sales\Services\CouponValidator;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Sales\Services\PromotionEngine;
use App\Domain\Sales\Services\TotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateQuotation (02-xx). DOC-only — no stock or GL effect.
 * Totals computed server-side via TotalsCalculator; unit prices via PricingService.
 * Optional coupon_code validated server-side (CouponValidator + CouponCalculator).
 */
class CreateQuotation
{
    public function __construct(
        protected TotalsCalculator $totals,
        protected PricingService $pricing,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected CouponValidator $couponValidator,
        protected CouponCalculator $couponCalculator,
        protected RedeemCoupon $redeemCoupon,
        protected PromotionEngine $promotionEngine,
        protected RecordPromotionUsage $recordPromotionUsage,
    ) {}

    /**
     * @param array{
     *   customer_id?: int|null,
     *   quote_date?: string,
     *   valid_until?: string|null,
     *   notes?: string|null,
     *   doc_discount?: float,
     *   shipping?: float,
     *   tax_code?: string|null,
     *   tax_applicable?: bool,
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null, discount?: float, description?: string|null}>
     * } $payload
     */
    public function handle(array $payload, Request $request): Quotation
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Quotation requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $request) {
            $docType = DocumentType::query()->where('code', 'quotation')->first()
                ?? abort(500, 'quotation document type is not seeded.');

            $quoteNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            // Resolve unit prices server-side
            $customer = isset($payload['customer_id']) && $payload['customer_id']
                ? Customer::query()->where('company_id', $companyId)->find($payload['customer_id'])
                : null;

            $priced = [];
            foreach ($lines as $i => $line) {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);

                $priced[$i] = [
                    'product_id' => $product->id,
                    'qty' => (float) ($line['qty'] ?? 0),
                    'unit_price' => $this->pricing->resolveUnitPrice(
                        $product,
                        $customer,
                        isset($line['unit_price']) && $line['unit_price'] !== null ? (float) $line['unit_price'] : null,
                        $payload['quote_date'] ?? null,
                        max(1, (int) (float) ($line['qty'] ?? 1)),
                    ),
                    'discount' => (float) ($line['discount'] ?? 0),
                    'description' => $line['description'] ?? null,
                ];
            }

            $baseDocDiscount = (float) ($payload['doc_discount'] ?? 0);
            $rawShipping = $payload['shipping'] ?? null;
            $shipping = ($rawShipping === null || $rawShipping === '')
                ? $this->pricing->resolveShipping(
                    $companyId,
                    $customer,
                    (float) ($payload['shipping_weight_kg'] ?? 0),
                )
                : (float) $rawShipping;
            $couponCode = null;
            $couponDiscount = 0.0;

            // Net subtotal before coupon (line gross − line discounts) for min_subtotal checks
            $netForCoupon = 0.0;
            foreach ($priced as $line) {
                $netForCoupon += round($line['qty'] * $line['unit_price'] - $line['discount'], 4);
            }
            $netForCoupon = max(0.0, $netForCoupon - $baseDocDiscount);

            $promotion = null;
            $promotionDiscount = 0.0;
            $promo = $this->promotionEngine->bestForCart(
                $companyId,
                $request->user()->default_branch_id,
                $priced,
                $netForCoupon,
                $payload['quote_date'] ?? null,
            );
            $promotion = $promo['promotion'];
            $promotionDiscount = $promo['discount'];
            $netForCoupon = max(0.0, $netForCoupon - $promotionDiscount);

            if (! empty($payload['coupon_code'])) {
                $coupon = $this->couponValidator->validate(
                    (string) $payload['coupon_code'],
                    $netForCoupon,
                    $customer,
                    $payload['quote_date'] ?? null,
                    $companyId,
                );
                $couponResult = $this->couponCalculator->calculate($coupon, $netForCoupon, $shipping, $priced);
                $couponCode = $coupon->code;
                $couponDiscount = $couponResult['discount'];
                $shipping = $couponResult['shipping_for_totals'];
            }

            $calc = $this->totals->calculate(
                $priced,
                $baseDocDiscount + $promotionDiscount + $couponDiscount,
                $shipping,
                $payload['tax_code'] ?? null,
                $payload['quote_date'] ?? now()->toDateString(),
                (bool) ($payload['tax_applicable'] ?? false),
            );

            $quote = Quotation::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'customer_id' => $payload['customer_id'] ?? null,
                'sales_person_id' => $payload['sales_person_id'] ?? null,
                'quote_no' => $quoteNo,
                'status' => 'draft',
                'quote_date' => $payload['quote_date'] ?? now()->toDateString(),
                'valid_until' => $payload['valid_until'] ?? null,
                'currency' => 'BDT',
                'subtotal' => $calc['subtotal'],
                'discount' => $calc['line_discount_total'] + $calc['doc_discount'],
                'coupon_code' => $couponCode,
                'coupon_discount' => number_format($couponDiscount, 4, '.', ''),
                'tax' => $calc['tax'],
                'shipping' => $calc['shipping'],
                'grand_total' => $calc['grand_total'],
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if ($couponCode !== null) {
                $this->redeemCoupon->handle(
                    $this->couponValidator->validate($couponCode, $netForCoupon, $customer, $payload['quote_date'] ?? null, $companyId),
                    'quotation',
                    $quote->id,
                    $couponDiscount,
                    $quote->customer_id,
                    $request,
                );
            }

            if ($promotion !== null && $promotionDiscount > 0) {
                $this->recordPromotionUsage->handle(
                    $promotion,
                    'quotation',
                    $quote->id,
                    $promotionDiscount,
                    $quote->customer_id,
                    $request,
                );
            }

            $lineNo = 0;
            foreach ($priced as $i => $line) {
                $lineNo++;
                $c = $calc['lines'][$i];

                QuotationLine::create([
                    'company_id' => $companyId,
                    'quotation_id' => $quote->id,
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'qty' => number_format($c['qty'], 4, '.', ''),
                    'unit_price' => number_format($c['unit_price'], 4, '.', ''),
                    'discount' => number_format($c['discount'], 4, '.', ''),
                    'tax' => number_format($c['tax'], 4, '.', ''),
                    'line_total' => number_format($c['line_total'], 4, '.', ''),
                ]);
            }

            $this->audit->record([
                'action' => 'sales.quotation_created',
                'entity_type' => 'quotation',
                'entity_id' => $quote->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'quote_no' => $quoteNo,
                    'grand_total' => (float) $quote->grand_total,
                    'line_count' => $lineNo,
                ],
            ]);

            return $quote->load('lines');
        });
    }
}
