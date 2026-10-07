<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\DeliveryChallanLine;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateDeliveryChallan (02-52 / delivery slice). Draft challan from a
 * confirmed (or later) order; moves order to ready_to_ship when allowed.
 * DOC only — no stock/GL at challan create (dispatch/issue remains separate).
 */
class CreateDeliveryChallan
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param  array{courier_name?: string|null, tracking_no?: string|null, notes?: string|null, lines?: array<int, array{product_id: int, qty: float}>}  $payload
     */
    public function handle(SalesOrder $order, array $payload, Request $request): DeliveryChallan
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $allowedFrom = ['confirmed', 'processing', 'ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery'];
        if (! in_array($order->status, $allowedFrom, true)) {
            throw new RuntimeException("Order {$order->order_no} cannot issue a delivery challan from status [{$order->status}].");
        }

        return DB::transaction(function () use ($order, $payload, $companyId, $request) {
            $fresh = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $docType = DocumentType::query()->where('code', 'delivery_challan')->first()
                ?? abort(500, 'delivery_challan document type is not seeded.');

            $challanNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $challan = DeliveryChallan::create([
                'company_id' => $companyId,
                'branch_id' => $fresh->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $fresh->warehouse_id,
                'sales_order_id' => $fresh->id,
                'document_type_id' => $docType->id,
                'challan_no' => $challanNo,
                'status' => 'draft',
                'printed_title' => $docType->printed_title,
                'challan_date' => now()->toDateString(),
                'courier_name' => $payload['courier_name'] ?? null,
                'tracking_no' => $payload['tracking_no'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $sourceLines = $payload['lines'] ?? null;
            if ($sourceLines === null || $sourceLines === []) {
                $sourceLines = $fresh->lines->map(fn ($l) => [
                    'product_id' => $l->product_id,
                    'qty' => $l->qty,
                    'description' => $l->description,
                ])->all();
            }

            $lineNo = 0;
            foreach ($sourceLines as $line) {
                $lineNo++;
                DeliveryChallanLine::create([
                    'company_id' => $companyId,
                    'delivery_challan_id' => $challan->id,
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'] ?? null,
                    'qty' => number_format((float) $line['qty'], 4, '.', ''),
                ]);
            }

            // Move order toward ready_to_ship when the state machine allows
            if ($this->stateMachine->canTransition($fresh->status, 'ready_to_ship')) {
                $this->stateMachine->transition($fresh, 'ready_to_ship');
            }

            $this->audit->record([
                'action' => 'sales.delivery_challan_created',
                'entity_type' => 'delivery_challan',
                'entity_id' => $challan->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'challan_no' => $challanNo,
                    'sales_order_id' => $fresh->id,
                    'order_no' => $fresh->order_no,
                    'line_count' => $lineNo,
                ],
            ]);

            return $challan->load('lines');
        });
    }
}
