<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesOrderLine;
use App\Domain\Sales\Services\CouponCalculator;
use App\Domain\Sales\Services\CouponValidator;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Sales\Services\PromotionEngine;
use App\Domain\Sales\Services\SuspiciousOrderFlagger;
use App\Domain\Sales\Services\TotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateSalesOrder (02-29). Save creates NO GL/stock effect — status
 * starts as pending. Reservation happens in ConfirmOrder.
 * Totals are server-authoritative (OrderTotalsServerAuthoritativeTest).
 * Optional coupon_code validated server-side.
 */
class CreateSalesOrder
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
        protected SuspiciousOrderFlagger $flagger,
    ) {}

    /**
     * @param array{
     *   customer_id?: int|null,
     *   warehouse_id?: int|null,
     *   source_quotation_id?: int|null,
     *   order_date?: string,
     *   notes?: string|null,
     *   doc_discount?: float,
     *   shipping?: float,
     *   tax_code?: string|null,
     *   tax_applicable?: bool,
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null, discount?: float, description?: string|null}>
     * } $payload
     */
    public function handle(array $payload, Request $request): SalesOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Sales order requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $request) {
            $docType = DocumentType::query()->where('code', 'sales_order')->first()
                ?? abort(500, 'sales_order document type is not seeded.');

            $orderNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

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
                        $payload['order_date'] ?? null,
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
                $payload['order_date'] ?? null,
            );
            $promotion = $promo['promotion'];
            $promotionDiscount = $promo['discount'];
            $netForCoupon = max(0.0, $netForCoupon - $promotionDiscount);

            if (! empty($payload['coupon_code'])) {
                $coupon = $this->couponValidator->validate(
                    (string) $payload['coupon_code'],
                    $netForCoupon,
                    $customer,
                    $payload['order_date'] ?? null,
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
                $payload['order_date'] ?? now()->toDateString(),
                (bool) ($payload['tax_applicable'] ?? false),
            );

            $order = SalesOrder::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'warehouse_id' => $payload['warehouse_id'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'sales_person_id' => $payload['sales_person_id'] ?? null,
                'source_quotation_id' => $payload['source_quotation_id'] ?? null,
                'order_no' => $orderNo,
                'status' => 'pending',
                'workflow_state' => 'none',
                'order_date' => $payload['order_date'] ?? now()->toDateString(),
                'currency' => 'BDT',
                'subtotal' => $calc['subtotal'],
                'discount' => $calc['line_discount_total'] + $calc['doc_discount'],
                'coupon_code' => $couponCode,
                'coupon_discount' => number_format($couponDiscount, 4, '.', ''),
                'tax' => $calc['tax'],
                'shipping' => $calc['shipping'],
                'grand_total' => $calc['grand_total'],
                'stock_reserved' => false,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if ($couponCode !== null) {
                $this->redeemCoupon->handle(
                    $this->couponValidator->validate($couponCode, $netForCoupon, $customer, $payload['order_date'] ?? null, $companyId),
                    'sales_order',
                    $order->id,
                    $couponDiscount,
                    $order->customer_id,
                    $request,
                );
            }

            if ($promotion !== null && $promotionDiscount > 0) {
                $this->recordPromotionUsage->handle(
                    $promotion,
                    'sales_order',
                    $order->id,
                    $promotionDiscount,
                    $order->customer_id,
                    $request,
                );
            }

            $lineNo = 0;
            foreach ($priced as $i => $line) {
                $lineNo++;
                $c = $calc['lines'][$i];

                SalesOrderLine::create([
                    'company_id' => $companyId,
                    'sales_order_id' => $order->id,
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

            // Score suspicion from the finished order (02-28) — flags only,
            // never a mutation of the order itself.
            $this->flagger->flag($order->load('lines'));

            $this->audit->record([
                'action' => 'sales.order_created',
                'entity_type' => 'sales_order',
                'entity_id' => $order->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'order_no' => $orderNo,
                    'status' => 'pending',
                    'grand_total' => (float) $order->grand_total,
                    'line_count' => $lineNo,
                ],
            ]);

            return $order->load('lines');
        });
    }
}
