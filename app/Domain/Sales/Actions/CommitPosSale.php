<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\InvoiceLine;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\Services\CouponCalculator;
use App\Domain\Sales\Services\CouponValidator;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Sales\Services\PromotionEngine;
use App\Domain\Sales\Services\TotalsCalculator;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CommitPosSale (02-30). Server-authoritative counter sale:
 * open session lock → price + totals (+ optional coupon) → draft POS
 * invoice → IssueInvoice (GL + SALES_OUT + COGS) → RecordInvoicePayment
 * → PosTransaction + session cash counters. client_uuid is idempotent.
 */
class CommitPosSale
{
    public function __construct(
        protected TotalsCalculator $totals,
        protected PricingService $pricing,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected IssueInvoice $issueInvoice,
        protected RecordInvoicePayment $recordPayment,
        protected CouponValidator $couponValidator,
        protected CouponCalculator $couponCalculator,
        protected RedeemCoupon $redeemCoupon,
        protected PromotionEngine $promotionEngine,
        protected RecordPromotionUsage $recordPromotionUsage,
        protected SettingService $settings,
    ) {}

    /**
     * @param array{
     *   pos_session_id?: int,
     *   customer_id?: int|null,
     *   warehouse_id?: int|null,
     *   payment_method?: string,
     *   tendered?: float|null,
     *   client_uuid?: string|null,
     *   tax_applicable?: bool,
     *   tax_code?: string|null,
     *   notes?: string|null,
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null, discount?: float, description?: string|null}>
     * } $payload
     */
    public function handle(array $payload, Request $request): PosTransaction
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('POS sale requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $request) {
            $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
            if ($clientUuid !== '') {
                $existing = PosTransaction::query()
                    ->where('company_id', $companyId)
                    ->where('client_uuid', $clientUuid)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $sessionId = (int) ($payload['pos_session_id'] ?? 0);
            $sessionQuery = PosSession::query()
                ->where('company_id', $companyId)
                ->where('status', 'open')
                ->lockForUpdate();

            $session = $sessionId > 0
                ? $sessionQuery->whereKey($sessionId)->first()
                : $sessionQuery
                    ->where('branch_id', $request->user()->default_branch_id)
                    ->first();

            if ($session === null) {
                throw new RuntimeException('No open POS session for this branch.');
            }

            if ($session->warehouse_id === null) {
                throw new RuntimeException('POS session has no warehouse; reopen with a warehouse to sell stock.');
            }

            $warehouseId = (int) $session->warehouse_id;

            $customer = isset($payload['customer_id']) && $payload['customer_id']
                ? Customer::query()->where('company_id', $companyId)->find($payload['customer_id'])
                : null;

            $priced = [];
            foreach ($lines as $i => $line) {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);

                if (! $product->is_active) {
                    throw new RuntimeException("Product {$product->sku} is inactive.");
                }

                $qty = (float) ($line['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('POS sale line qty must be greater than zero.');
                }

                $priced[$i] = [
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'unit_price' => $this->pricing->resolveUnitPrice(
                        $product,
                        $customer,
                        isset($line['unit_price']) && $line['unit_price'] !== null ? (float) $line['unit_price'] : null,
                        now()->toDateString(),
                        max(1, (int) $qty),
                    ),
                    'discount' => (float) ($line['discount'] ?? 0),
                    'description' => $line['description'] ?? null,
                ];
            }

            $taxApplicable = (bool) ($payload['tax_applicable'] ?? false);
            $taxCode = $payload['tax_code'] ?? null;

            $baseDocDiscount = 0.0;
            $shipping = 0.0;
            $couponCode = null;
            $couponDiscount = 0.0;

            $netForCoupon = 0.0;
            foreach ($priced as $line) {
                $netForCoupon += round($line['qty'] * $line['unit_price'] - $line['discount'], 4);
            }
            $netForCoupon = max(0.0, $netForCoupon);

            $promotion = null;
            $promotionDiscount = 0.0;
            $promo = $this->promotionEngine->bestForCart(
                $companyId,
                $session->branch_id ?? $request->user()->default_branch_id,
                $priced,
                $netForCoupon,
                now()->toDateString(),
            );
            $promotion = $promo['promotion'];
            $promotionDiscount = $promo['discount'];
            $netForCoupon = max(0.0, $netForCoupon - $promotionDiscount);

            if (! empty($payload['coupon_code'])) {
                $coupon = $this->couponValidator->validate(
                    (string) $payload['coupon_code'],
                    $netForCoupon,
                    $customer,
                    now()->toDateString(),
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
                $taxCode,
                now()->toDateString(),
                $taxApplicable,
            );

            $method = $payload['payment_method'] ?? 'cash';
            if (! in_array($method, ['cash', 'bank', 'cheque', 'mobile'], true)) {
                throw new RuntimeException("Unsupported POS payment method [{$method}].");
            }

            // 02-47 cash rounding: only the configured increment (> 0.01) moves
            // the total; the adjustment lands in the invoice rounding column so
            // GL/stock keep matching the (rounded) grand total.
            $grand = (float) $calc['grand_total'];
            $increment = (float) $this->settings->get('pos', 'rounding_increment', null);
            if ($increment > 0.011) {
                // epsilon keeps true half-way values half-up through FP noise
                $rounded = round(round($grand / $increment + 1e-9, 0) * $increment, 2);
                $calc['rounding'] = round($calc['rounding'] + ($rounded - $grand), 4);
                $calc['grand_total'] = $rounded;
                $grand = $rounded;
            }

            $rawTendered = $payload['tendered'] ?? null;
            $tendered = $rawTendered !== null && $rawTendered !== ''
                ? (float) $rawTendered
                : $grand;
            $changeDue = 0.0;

            if ($method === 'cash') {
                if ($tendered + 1e-9 < $grand) {
                    throw new RuntimeException(sprintf(
                        'Tendered %.4f is less than total %.4f.',
                        $tendered,
                        $grand,
                    ));
                }
                $changeDue = round($tendered - $grand, 4);
            } else {
                $tendered = $grand;
            }

            $docType = DocumentType::query()->where('code', 'invoice')->first()
                ?? abort(500, 'invoice document type is not seeded.');

            $invoiceNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'branch_id' => $session->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customer?->id,
                'sales_order_id' => null,
                'document_type_id' => $docType->id,
                'pos_session_id' => $session->id,
                'invoice_no' => $invoiceNo,
                'status' => 'draft',
                'invoice_type' => 'pos',
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'invoice_date' => now()->toDateString(),
                'due_date' => null,
                'currency' => 'BDT',
                'subtotal' => $calc['subtotal'],
                'doc_discount' => $calc['doc_discount'],
                'coupon_code' => $couponCode,
                'coupon_discount' => number_format($couponDiscount, 4, '.', ''),
                'taxable_base' => $calc['taxable_base'],
                'tax' => $calc['tax'],
                'shipping' => 0,
                'rounding' => $calc['rounding'],
                'grand_total' => $grand,
                'paid_amount' => 0,
                'due_amount' => $grand,
                'tax_applicable' => $taxApplicable,
                'tax_code' => $taxCode,
                'printed_title' => $docType->printed_title,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($priced as $i => $line) {
                $lineNo++;
                $c = $calc['lines'][$i];

                InvoiceLine::create([
                    'company_id' => $companyId,
                    'invoice_id' => $invoice->id,
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'sales_order_line_id' => null,
                    'description' => $line['description'],
                    'qty' => number_format($c['qty'], 4, '.', ''),
                    'unit_price' => number_format($c['unit_price'], 4, '.', ''),
                    'discount' => number_format($c['discount'], 4, '.', ''),
                    'tax' => number_format($c['tax'], 4, '.', ''),
                    'line_total' => number_format($c['line_total'], 4, '.', ''),
                ]);
            }

            $this->issueInvoice->handle($invoice->fresh('lines'), $request);

            if ($couponCode !== null) {
                $this->redeemCoupon->handle(
                    $this->couponValidator->validate($couponCode, $netForCoupon, $customer, now()->toDateString(), $companyId),
                    'invoice',
                    $invoice->id,
                    $couponDiscount,
                    $invoice->customer_id,
                    $request,
                );
            }

            if ($promotion !== null && $promotionDiscount > 0) {
                $this->recordPromotionUsage->handle(
                    $promotion,
                    'invoice',
                    $invoice->id,
                    $promotionDiscount,
                    $invoice->customer_id,
                    $request,
                );
            }

            $paymentKey = $clientUuid !== ''
                ? 'pos-pay:'.$clientUuid
                : 'pos-pay:'.$invoice->id.'-'.uniqid('', true);

            $this->recordPayment->handle([
                'invoice_id' => $invoice->id,
                'amount' => $grand,
                'method' => $method,
                'narration' => "POS {$session->session_no}",
                'idempotency_key' => $paymentKey,
            ], $request);

            $transaction = PosTransaction::create([
                'company_id' => $companyId,
                'pos_session_id' => $session->id,
                'invoice_id' => $invoice->id,
                'customer_id' => $customer?->id,
                'client_uuid' => $clientUuid !== '' ? $clientUuid : null,
                'status' => 'completed',
                'sync_state' => 'synced',
                'total' => number_format($grand, 4, '.', ''),
                'payment_method' => $method,
                'tendered' => number_format($tendered, 4, '.', ''),
                'change_due' => number_format($changeDue, 4, '.', ''),
                'sold_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            if ($method === 'cash') {
                $session->cash_sales = number_format((float) $session->cash_sales + $grand, 4, '.', '');
            } else {
                $session->non_cash_sales = number_format((float) $session->non_cash_sales + $grand, 4, '.', '');
            }
            $session->save();

            $this->audit->record([
                'action' => 'pos.sale_committed',
                'entity_type' => 'pos_transaction',
                'entity_id' => $transaction->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'session_no' => $session->session_no,
                    'invoice_no' => $invoiceNo,
                    'total' => $grand,
                    'payment_method' => $method,
                    'change_due' => $changeDue,
                    'client_uuid' => $clientUuid !== '' ? $clientUuid : null,
                ],
            ]);

            return $transaction->load('invoice');
        });
    }
}
