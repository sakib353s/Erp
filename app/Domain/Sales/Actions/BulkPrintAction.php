<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\ShippingLabel;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BulkPrintAction (02-07): bulk document printing for orders. Each
 * order is processed in its own try/catch so one failure never blocks
 * the rest — an order without an issued invoice simply fails with that
 * exact message. Every print renders a real file (DocumentRenderer),
 * writes its print_history row, and a bulk audit row closes the run.
 */
class BulkPrintAction
{
    public const OUTCOME_PRINTED = 'printed';

    public const OUTCOME_FAILED = 'failed';

    /** @var array<int, string> */
    public const TYPES = ['invoice', 'packing_slip', 'shipping_label'];

    public const DOCUMENTS = [
        'invoice' => 'invoice',
        'packing_slip' => 'packing_slip',
        'shipping_label' => 'shipping_label',
    ];

    public const MAX_PER_RUN = 100;

    public function __construct(
        protected DocumentRenderer $renderer,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array<int, int|string>  $orderIds
     * @param  array<string, mixed>  $payload
     * @return array{
     *   type: string,
     *   requested: int,
     *   counts: array<string, int>,
     *   results: array<int, array{order_id: int, order_no: ?string, outcome: string, message: string}>
     * }
     */
    public function handle(string $type, array $orderIds, array $payload, Request $request): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Unsupported bulk print type [{$type}].");
        }

        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            throw new RuntimeException('Select at least one order.');
        }

        if (count($ids) > self::MAX_PER_RUN) {
            throw new RuntimeException('A bulk action accepts at most '.self::MAX_PER_RUN.' orders per run.');
        }

        $correlationId = (string) Str::uuid();
        $results = [];
        $counts = [
            self::OUTCOME_PRINTED => 0,
            self::OUTCOME_FAILED => 0,
        ];

        foreach ($ids as $id) {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->find($id);

            if ($order === null) {
                $counts[self::OUTCOME_FAILED]++;
                $results[] = [
                    'order_id' => $id,
                    'order_no' => null,
                    'outcome' => self::OUTCOME_FAILED,
                    'message' => 'Order not found.',
                ];

                continue;
            }

            try {
                [$outcome, $message] = $this->runPrint($order, $type, $request, $correlationId);
            } catch (\Throwable $e) {
                $outcome = self::OUTCOME_FAILED;
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unexpected failure.';
            }

            $counts[$outcome]++;
            $results[] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'outcome' => $outcome,
                'message' => $message,
            ];
        }

        $this->audit->record([
            'action' => "sales.order_bulk_print_{$type}",
            'entity_type' => 'sales_order',
            'entity_id' => $results[0]['order_id'],
            'actor_id' => $request->user()->id,
            'after' => [
                'action' => 'print_'.$type,
                'requested' => count($ids),
                'printed' => $counts[self::OUTCOME_PRINTED],
                'failed' => $counts[self::OUTCOME_FAILED],
                'correlation_id' => $correlationId,
                'order_ids' => $ids,
            ],
            'reason' => null,
        ]);

        return [
            'type' => $type,
            'requested' => count($ids),
            'counts' => $counts,
            'results' => $results,
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function runPrint(SalesOrder $order, string $type, Request $request, string $correlationId): array
    {
        if ($type === 'packing_slip') {
            return $this->runPrintPackingSlip($order, $request, $correlationId);
        }

        if ($type === 'shipping_label') {
            return $this->runPrintShippingLabel($order, $request, $correlationId);
        }

        $invoice = $order->invoices()
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->orderByDesc('id')
            ->first();

        if ($invoice === null) {
            return [self::OUTCOME_FAILED, 'No issued invoice for this order.'];
        }

        $document = $this->renderer->renderInvoice($invoice, $request->user());
        $this->renderer->recordPrint($invoice, 'invoice', $request, $correlationId);

        return [
            self::OUTCOME_PRINTED,
            "Printed {$invoice->invoice_no} (document #{$document->id}, html).",
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function runPrintPackingSlip(SalesOrder $order, Request $request, string $correlationId): array
    {
        if ($order->lines()->count() === 0) {
            return [self::OUTCOME_FAILED, 'Order has no lines to pack.'];
        }

        $document = $this->renderer->renderPackingSlip($order, $request->user());
        $this->renderer->recordPrint($order, 'packing_slip', $request, $correlationId);

        return [
            self::OUTCOME_PRINTED,
            "Printed packing slip {$order->order_no} (document #{$document->id}, html).",
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function runPrintShippingLabel(SalesOrder $order, Request $request, string $correlationId): array
    {
        $customer = $order->customer()->first();

        if ($customer === null) {
            return [self::OUTCOME_FAILED, 'Order has no customer for the shipping label.'];
        }

        if (! filled($customer->phone)) {
            return [self::OUTCOME_FAILED, 'Customer has no phone number for the shipping label.'];
        }

        $district = $customer->district()->first();

        if ($district === null) {
            return [self::OUTCOME_FAILED, 'Customer has no district for the shipping label.'];
        }

        $type = DocumentType::query()->where('code', 'shipping_label')->first();

        if ($type === null) {
            return [self::OUTCOME_FAILED, 'shipping_label document type is not seeded.'];
        }

        $shipment = Shipment::query()
            ->where('sales_order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $labelNo = $this->numbering->allocate(
            $type->id,
            $order->branch_id ?? $request->user()->default_branch_id,
        );

        $label = ShippingLabel::query()->create([
            'company_id' => $order->company_id,
            'sales_order_id' => $order->id,
            'shipment_id' => $shipment?->id,
            'courier_id' => $shipment?->courier_id,
            'label_no' => $labelNo,
            'receiver_name' => $customer->name,
            'receiver_phone' => $customer->phone,
            'receiver_address' => (string) $customer->address_line1,
            'district' => $district->name,
            'tracking_code' => $shipment?->external_ref,
            'created_by' => $request->user()->id,
        ]);

        $document = $this->renderer->renderShippingLabel($label, $order, $request->user());
        $this->renderer->recordPrint($label, 'shipping_label', $request, $correlationId);

        return [
            self::OUTCOME_PRINTED,
            "Printed shipping label {$labelNo} (document #{$document->id}, html).",
        ];
    }
}
