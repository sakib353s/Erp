<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use App\Domain\Sales\Promotion;
use App\Domain\Sales\PromotionUsage;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesOrderLine;
use App\Domain\Sales\Services\CouponCalculator;
use App\Domain\Sales\Services\CouponValidator;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Sales\Services\PromotionEngine;
use App\Domain\Sales\Services\TotalsCalculator;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * UpdateSalesOrder (02-03): draft/pending only.
 *
 *  - never touches stock or GL (the order has no reservation and no invoice
 *    yet — both are hard guards), totals are re-derived server-side from
 *    PricingService + TotalsCalculator, client-sent totals are ignored;
 *  - coupon / promotion usage rows are re-synced with the new amounts so
 *    reports keep attributing the real discount (a cleared coupon frees its
 *    usage slot again);
 *  - a material change invalidates any pending approval snapshot through the
 *    generic WorkflowEngine (approval cancellation authority still applies).
 */
class UpdateSalesOrder
{
    public function __construct(
        protected TotalsCalculator $totals,
        protected PricingService $pricing,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected CouponValidator $couponValidator,
        protected CouponCalculator $couponCalculator,
        protected PromotionEngine $promotionEngine,
        protected WorkflowEngine $workflow,
    ) {}

    /**
     * @param array{
     *   customer_id?: int|null,
     *   warehouse_id?: int|null,
     *   order_date?: string,
     *   notes?: string|null,
     *   doc_discount?: float,
     *   shipping?: float,
     *   tax_code?: string|null,
     *   tax_applicable?: bool,
     *   coupon_code?: string|null,
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null, discount?: float, description?: string|null}>
     * } $payload
     */
    public function handle(SalesOrder $order, array $payload, Request $request): SalesOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($order, $payload, $companyId, $request) {
            $fresh = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($fresh);

            $lines = $payload['lines'] ?? [];
            if ($lines === []) {
                throw new RuntimeException('Sales order requires at least one line.');
            }

            $orderDate = $payload['order_date'] ?? $fresh->order_date?->toDateString();
            $customer = isset($payload['customer_id']) && $payload['customer_id']
                ? Customer::query()->where('company_id', $companyId)->find($payload['customer_id'])
                : null;

            if (isset($payload['customer_id']) && $payload['customer_id'] && $customer === null) {
                throw new RuntimeException('Customer does not belong to this company.');
            }

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
                        $orderDate,
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
            $taxCode = $payload['tax_code'] ?? null;
            $taxApplicable = (bool) ($payload['tax_applicable'] ?? false);

            $netForCoupon = 0.0;
            foreach ($priced as $line) {
                $netForCoupon += round($line['qty'] * $line['unit_price'] - $line['discount'], 4);
            }
            $netForCoupon = max(0.0, $netForCoupon - $baseDocDiscount);

            $promo = $this->promotionEngine->bestForCart(
                $companyId,
                $fresh->branch_id ?? $request->user()->default_branch_id,
                $priced,
                $netForCoupon,
                $orderDate,
            );
            $promotion = $promo['promotion'];
            $promotionDiscount = $promo['discount'];
            $netForCoupon = max(0.0, $netForCoupon - $promotionDiscount);

            $targetCouponCode = array_key_exists('coupon_code', $payload)
                ? ($payload['coupon_code'] !== null && $payload['coupon_code'] !== ''
                    ? (string) $payload['coupon_code']
                    : null)
                : $fresh->coupon_code;

            $coupon = null;
            $couponDiscount = 0.0;

            if ($targetCouponCode !== null) {
                $coupon = $this->couponValidator->validate(
                    $targetCouponCode,
                    $netForCoupon,
                    $customer,
                    $orderDate,
                    $companyId,
                );
                $couponResult = $this->couponCalculator->calculate($coupon, $netForCoupon, $shipping, $priced);
                $couponDiscount = $couponResult['discount'];
                $shipping = $couponResult['shipping_for_totals'];
            }

            $calc = $this->totals->calculate(
                $priced,
                $baseDocDiscount + $promotionDiscount + $couponDiscount,
                $shipping,
                $taxCode,
                $orderDate,
                $taxApplicable,
            );

            $existingLines = $fresh->lines()->orderBy('line_no')->get();
            $materialBefore = $this->signature($fresh, $existingLines);
            $before = [
                'customer_id' => $fresh->customer_id,
                'grand_total' => (float) $fresh->grand_total,
                'line_count' => $existingLines->count(),
            ];

            $fresh->customer_id = $customer?->id;
            $fresh->warehouse_id = $payload['warehouse_id'] ?? $fresh->warehouse_id;
            $fresh->order_date = $orderDate;
            $fresh->subtotal = $calc['subtotal'];
            $fresh->discount = $calc['line_discount_total'] + $calc['doc_discount'];
            $fresh->coupon_code = $coupon?->code;
            $fresh->coupon_discount = number_format($couponDiscount, 4, '.', '');
            $fresh->tax = $calc['tax'];
            $fresh->shipping = $calc['shipping'];
            $fresh->grand_total = $calc['grand_total'];
            $fresh->notes = $payload['notes'] ?? $fresh->notes;
            $fresh->save();

            SalesOrderLine::query()->where('sales_order_id', $fresh->id)->delete();

            $lineNo = 0;
            $written = [];
            foreach ($priced as $i => $line) {
                $lineNo++;
                $c = $calc['lines'][$i];
                SalesOrderLine::create([
                    'company_id' => $companyId,
                    'sales_order_id' => $fresh->id,
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'qty' => number_format($c['qty'], 4, '.', ''),
                    'unit_price' => number_format($c['unit_price'], 4, '.', ''),
                    'discount' => number_format($c['discount'], 4, '.', ''),
                    'tax' => number_format($c['tax'], 4, '.', ''),
                    'line_total' => number_format($c['line_total'], 4, '.', ''),
                ]);
                $written[] = [
                    'product_id' => $line['product_id'],
                    'qty' => $c['qty'],
                    'unit_price' => $c['unit_price'],
                    'discount' => $c['discount'],
                ];
            }

            $this->syncCouponUsage($fresh, $coupon, $couponDiscount);
            $this->syncPromotionUsage($fresh, $promotion, $promotionDiscount);

            $materialAfter = $this->signature($fresh, $written);
            $invalidations = $materialBefore !== $materialAfter
                ? $this->invalidatePendingApprovals($fresh, $request)
                : 0;

            $this->audit->record([
                'action' => 'sales.order_updated',
                'entity_type' => 'sales_order',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'before' => [
                    'customer_id' => $before['customer_id'],
                    'grand_total' => $before['grand_total'],
                    'line_count' => $before['line_count'],
                ],
                'after' => [
                    'order_no' => $fresh->order_no,
                    'grand_total' => (float) $fresh->grand_total,
                    'line_count' => $lineNo,
                    'coupon_code' => $fresh->coupon_code,
                    'approvals_invalidated' => $invalidations,
                ],
                'result' => $invalidations > 0 ? 'approval_invalidated' : 'success',
            ]);

            return $fresh->load('lines');
        });
    }

    protected function assertEditable(SalesOrder $order): void
    {
        // Most specific first: a frozen document (invoiced) never becomes
        // editable again even if its status later changes.
        if ($order->invoices()->exists()) {
            throw new RuntimeException("Order {$order->order_no} already has an invoice and cannot be edited.");
        }

        if ($order->status !== 'pending') {
            throw new RuntimeException(
                "Order {$order->order_no} can only be edited while pending (status [{$order->status}])."
            );
        }

        if ($order->stock_reserved) {
            throw new RuntimeException("Order {$order->order_no} has reserved stock and cannot be edited.");
        }
    }

    /**
     * Coupon usage follows the document: a changed coupon replaces the usage
     * row (freeing the old slot), a cleared coupon removes it, and an
     * unchanged coupon only has its attributed discount refreshed.
     */
    protected function syncCouponUsage(
        SalesOrder $order,
        ?Coupon $coupon,
        float $discount,
    ): void {
        $usage = CouponUsage::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->first();

        if ($usage !== null && ($coupon === null || (int) $usage->coupon_id !== (int) $coupon->id)) {
            // Coupon removed or replaced → the old redemption frees its slot.
            $this->releaseCouponSlot((int) $usage->coupon_id, $usage);
            $usage = null;
        }

        if ($usage !== null) {
            $usage->forceFill([
                'discount_amount' => number_format($discount, 4, '.', ''),
                'customer_id' => $order->customer_id,
            ])->save();

            return;
        }

        if ($coupon !== null) {
            $this->redeem($coupon, $order, $discount);
        }
    }

    protected function redeem(?Coupon $coupon, SalesOrder $order, float $discount): void
    {
        if ($coupon === null) {
            return;
        }

        $fresh = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

        if ($fresh->max_uses !== null && (int) $fresh->used_count >= (int) $fresh->max_uses) {
            throw new RuntimeException("Coupon [{$fresh->code}] has reached its usage limit.");
        }

        CouponUsage::create([
            'company_id' => $order->company_id,
            'coupon_id' => $fresh->id,
            'source_type' => 'sales_order',
            'source_id' => $order->id,
            'customer_id' => $order->customer_id,
            'discount_amount' => number_format($discount, 4, '.', ''),
            'used_at' => now(),
        ]);

        $fresh->used_count = (int) $fresh->used_count + 1;
        $fresh->save();
    }

    protected function releaseCouponSlot(int $couponId, CouponUsage $usage): void
    {
        $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->first();
        if ($coupon !== null) {
            $coupon->used_count = max(0, (int) $coupon->used_count - 1);
            $coupon->save();
        }

        $usage->delete();
    }

    protected function syncPromotionUsage(
        SalesOrder $order,
        ?Promotion $promotion,
        float $discount,
    ): void {
        $usage = PromotionUsage::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->first();

        if ($usage !== null && ($promotion === null || (int) $usage->promotion_id !== (int) $promotion->id)) {
            $usage->delete();
            $usage = null;
        }

        if ($usage !== null) {
            $usage->forceFill([
                'discount_amount' => number_format($discount, 4, '.', ''),
                'customer_id' => $order->customer_id,
            ])->save();

            return;
        }

        if ($promotion !== null && $discount > 0) {
            PromotionUsage::create([
                'company_id' => $order->company_id,
                'promotion_id' => $promotion->id,
                'source_type' => 'sales_order',
                'source_id' => $order->id,
                'customer_id' => $order->customer_id,
                'discount_amount' => number_format($discount, 4, '.', ''),
                'used_at' => now(),
            ]);
        }
    }

    /**
     * Material change → the frozen approval snapshot no longer describes the
     * document: cancel pending requests through the generic engine (the
     * engine decides who may cancel), otherwise the edit is refused.
     */
    protected function invalidatePendingApprovals(SalesOrder $order, Request $request): int
    {
        $pending = ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $order->id)
            ->where('status', 'pending')
            ->get();

        $cancelled = 0;

        foreach ($pending as $approval) {
            try {
                $this->workflow->cancel(
                    $approval->id,
                    $request->user(),
                    'Order edited — approval snapshot invalidated.',
                );
                $cancelled++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    throw new RuntimeException(
                        'Only the requester or an approver may edit an order that is awaiting approval.'
                    );
                }

                throw $e;
            }
        }

        return $cancelled;
    }

    /**
     * @param  iterable<array{product_id: int, qty?: mixed, unit_price?: mixed, discount?: mixed}>|Collection  $lines
     */
    protected function signature(SalesOrder $order, $lines): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = [
                (int) $line['product_id'],
                (string) (float) ($line['qty'] ?? 0),
                (string) (float) ($line['unit_price'] ?? 0),
                (string) (float) ($line['discount'] ?? 0),
            ];
        }

        sort($rows);

        return sha1(json_encode([
            'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
            'warehouse_id' => $order->warehouse_id !== null ? (int) $order->warehouse_id : null,
            'order_date' => $order->order_date?->toDateString(),
            'grand_total' => (string) (float) $order->grand_total,
            'lines' => $rows,
        ]));
    }
}
