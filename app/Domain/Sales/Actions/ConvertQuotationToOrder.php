<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesOrderLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ConvertQuotationToOrder (02-73). Line mapping with provenance link
 * (source_quotation_id); no qty loss. Resulting order is pending —
 * stock reserved only on ConfirmOrder.
 */
class ConvertQuotationToOrder
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Quotation $quotation, array $payload, Request $request): SalesOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (in_array($quotation->status, ['converted'], true)) {
            throw new RuntimeException("Quotation {$quotation->quote_no} is already converted.");
        }

        if ($quotation->status === 'declined') {
            throw new RuntimeException("Quotation {$quotation->quote_no} is declined and cannot be converted.");
        }

        return DB::transaction(function () use ($quotation, $payload, $companyId, $request) {
            $fresh = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            $docType = DocumentType::query()->where('code', 'sales_order')->first()
                ?? abort(500, 'sales_order document type is not seeded.');

            $orderNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $order = SalesOrder::create([
                'company_id' => $companyId,
                'branch_id' => $fresh->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $payload['warehouse_id'] ?? null,
                'customer_id' => $fresh->customer_id,
                'source_quotation_id' => $fresh->id,
                'order_no' => $orderNo,
                'status' => 'pending',
                'workflow_state' => 'none',
                'order_date' => $payload['order_date'] ?? now()->toDateString(),
                'currency' => $fresh->currency,
                'subtotal' => $fresh->subtotal,
                'discount' => $fresh->discount,
                'tax' => $fresh->tax,
                'shipping' => $fresh->shipping,
                'grand_total' => $fresh->grand_total,
                'stock_reserved' => false,
                'notes' => $payload['notes'] ?? $fresh->notes,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($fresh->lines as $ql) {
                $lineNo++;
                SalesOrderLine::create([
                    'company_id' => $companyId,
                    'sales_order_id' => $order->id,
                    'line_no' => $lineNo,
                    'product_id' => $ql->product_id,
                    'description' => $ql->description,
                    'qty' => $ql->qty,
                    'unit_price' => $ql->unit_price,
                    'discount' => $ql->discount,
                    'tax' => $ql->tax,
                    'line_total' => $ql->line_total,
                ]);
            }

            $fresh->status = 'converted';
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.quotation_converted',
                'entity_type' => 'sales_order',
                'entity_id' => $order->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'order_no' => $orderNo,
                    'source_quotation_id' => $fresh->id,
                    'source_quote_no' => $fresh->quote_no,
                    'line_count' => $lineNo,
                    'grand_total' => (float) $order->grand_total,
                ],
            ]);

            return $order->load('lines');
        });
    }
}
