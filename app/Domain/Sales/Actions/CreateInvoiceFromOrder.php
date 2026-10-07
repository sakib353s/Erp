<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\InvoiceLine;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\TotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateInvoiceFromOrder (02-49). Draft invoice under lock; D10 title
 * from document_types; NO GL / NO stock until IssueInvoice.
 */
class CreateInvoiceFromOrder
{
    public function __construct(
        protected TotalsCalculator $totals,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(SalesOrder $order, array $payload, Request $request): Invoice
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (! in_array($order->status, ['confirmed', 'processing', 'ready_to_ship', 'delivered', 'completed'], true)) {
            throw new RuntimeException("Order {$order->order_no} is not ready to invoice (status [{$order->status}]).");
        }

        $existing = $order->invoices()->whereNotIn('status', ['void'])->first();
        if ($existing !== null) {
            // Layaway deposit (02-41): the order already carries its deposit
            // invoice — never bill the goods on top of it. Settlement of
            // the remaining balance ships with installment collection.
            if ($existing->invoice_type === 'layaway') {
                throw new RuntimeException(sprintf(
                    'Order %s has layaway deposit %s recorded; settle the balance before delivery invoicing.',
                    $order->order_no,
                    $existing->invoice_no,
                ));
            }

            throw new RuntimeException("Order {$order->order_no} already has an invoice.");
        }

        return DB::transaction(function () use ($order, $payload, $companyId, $request) {
            $docType = DocumentType::query()->where('code', 'invoice')->first()
                ?? abort(500, 'invoice document type is not seeded.');

            $invoiceNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $taxApplicable = (bool) ($payload['tax_applicable'] ?? false);
            $taxCode = $payload['tax_code'] ?? null;

            $lineDiscountTotal = 0.0;
            foreach ($order->lines as $orderLine) {
                $lineDiscountTotal += (float) $orderLine->discount;
            }
            $lineDiscountTotal = round($lineDiscountTotal, 4);
            $docDiscount = round(max(0, (float) $order->discount - $lineDiscountTotal), 4);
            $taxableBase = round(max(0, (float) $order->subtotal - $lineDiscountTotal - $docDiscount), 4);

            // Preserve order grand (includes coupon/promo doc discount). Without tax,
            // recompute the same way TotalsCalculator does: nets − doc + shipping.
            $grand = $taxApplicable
                ? (float) $order->grand_total
                : round($taxableBase + (float) $order->shipping, 2);

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'branch_id' => $order->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $order->warehouse_id,
                'customer_id' => $order->customer_id,
                'sales_person_id' => $order->sales_person_id,
                'sales_order_id' => $order->id,
                'document_type_id' => $docType->id,
                'invoice_no' => $invoiceNo,
                'status' => 'draft',
                'invoice_type' => 'standard',
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'invoice_date' => $payload['invoice_date'] ?? now()->toDateString(),
                'due_date' => $payload['due_date'] ?? null,
                'currency' => 'BDT',
                'subtotal' => $order->subtotal,
                'doc_discount' => number_format($docDiscount, 4, '.', ''),
                'coupon_code' => $order->coupon_code,
                'coupon_discount' => $order->coupon_discount,
                'taxable_base' => number_format($taxableBase, 4, '.', ''),
                'tax' => $taxApplicable ? $order->tax : 0,
                'shipping' => $order->shipping,
                'rounding' => 0,
                'grand_total' => $grand,
                'paid_amount' => 0,
                'due_amount' => $grand,
                'tax_applicable' => $taxApplicable,
                'tax_code' => $taxCode,
                'printed_title' => $docType->printed_title,
                'notes' => $payload['notes'] ?? $order->notes,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($order->lines as $orderLine) {
                $lineNo++;
                $taxLine = $taxApplicable ? (float) $orderLine->tax : 0.0;
                $net = round((float) $orderLine->qty * (float) $orderLine->unit_price - (float) $orderLine->discount, 4);

                InvoiceLine::create([
                    'company_id' => $companyId,
                    'invoice_id' => $invoice->id,
                    'line_no' => $lineNo,
                    'product_id' => $orderLine->product_id,
                    'sales_order_line_id' => $orderLine->id,
                    'description' => $orderLine->description,
                    'qty' => $orderLine->qty,
                    'unit_price' => $orderLine->unit_price,
                    'discount' => $orderLine->discount,
                    'tax' => number_format($taxLine, 4, '.', ''),
                    'line_total' => number_format(round($net + $taxLine, 4), 4, '.', ''),
                ]);
            }

            $this->audit->record([
                'action' => 'sales.invoice_created',
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'invoice_no' => $invoiceNo,
                    'status' => 'draft',
                    'printed_title' => $invoice->printed_title,
                    'grand_total' => (float) $invoice->grand_total,
                ],
            ]);

            return $invoice->load('lines');
        });
    }
}
