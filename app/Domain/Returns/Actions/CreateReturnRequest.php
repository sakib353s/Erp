<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Returns\ExchangeLine;
use App\Domain\Returns\SalesReturn;
use App\Domain\Returns\SalesReturnLine;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\InvoiceLine;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateReturnRequest (07-03/07-07). Links invoice lines with partial qty
 * validation. DOC + status only — no stock/GL until receive/credit issue.
 * A delivered/completed order moves to return_requested (02-24) through
 * the state machine; orders in any other state keep their status.
 */
class CreateReturnRequest
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param array{
     *   invoice_id: int,
     *   return_reason_id?: int|null,
     *   source?: string,
     *   notes?: string|null,
     *   lines: array<int, array{invoice_line_id: int, qty: float}>
     * } $payload
     */
    public function handle(array $payload, Request $request): SalesReturn
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Return requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $request) {
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->findOrFail($payload['invoice_id']);

            if (! in_array($invoice->status, ['issued', 'partial', 'paid'], true)) {
                throw new RuntimeException("Invoice {$invoice->invoice_no} is not open for returns (status [{$invoice->status}]).");
            }

            $docType = DocumentType::query()->where('code', 'credit_note')->first();
            $returnNo = $docType !== null
                ? 'RET-'.$this->numbering->allocate($docType->id, $request->user()->default_branch_id)
                : 'RET-'.now()->format('YmdHis').'-'.uniqid();

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $resolved = [];

            foreach ($lines as $line) {
                $invoiceLine = InvoiceLine::query()
                    ->where('company_id', $companyId)
                    ->where('invoice_id', $invoice->id)
                    ->findOrFail($line['invoice_line_id']);

                $returnQty = (float) ($line['qty'] ?? 0);
                if ($returnQty <= 0) {
                    throw new RuntimeException('Return line qty must be greater than zero.');
                }

                $alreadyReturned = (float) SalesReturnLine::query()
                    ->whereHas('salesReturn', function ($q) use ($companyId, $invoice) {
                        $q->where('company_id', $companyId)
                            ->where('invoice_id', $invoice->id)
                            ->whereIn('status', ['requested', 'approved', 'received', 'inspected', 'credited', 'refunded']);
                    })
                    ->where('invoice_line_id', $invoiceLine->id)
                    ->sum('qty')
                    // Exchanges settle on their own document (exchange_lines has
                    // no status yet — every row is a completed exchange), so the
                    // remaining-qty guard spans returns AND exchanges (02-39).
                    + (float) ExchangeLine::query()
                        ->where('invoice_line_id', $invoiceLine->id)
                        ->sum('qty');

                $invoiced = (float) $invoiceLine->qty;
                if ($alreadyReturned + $returnQty > $invoiced + 1e-9) {
                    throw new RuntimeException(sprintf(
                        'Return qty %.4f exceeds remaining invoiced qty %.4f on line %d.',
                        $returnQty,
                        $invoiced - $alreadyReturned,
                        $invoiceLine->line_no,
                    ));
                }

                $unit = (float) $invoiceLine->unit_price;
                $lineTaxRatio = (float) $invoiceLine->qty > 0
                    ? ((float) $invoiceLine->tax) / (float) $invoiceLine->qty
                    : 0.0;
                $tax = round($returnQty * $lineTaxRatio, 4);
                $net = round($returnQty * $unit, 4);
                $lineTotal = round($net + $tax, 4);
                $subtotal += $net;
                $taxTotal += $tax;

                $resolved[] = [
                    'invoice_line_id' => $invoiceLine->id,
                    'product_id' => $invoiceLine->product_id,
                    'description' => $invoiceLine->description,
                    'qty' => $returnQty,
                    'unit_price' => $unit,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            $return = SalesReturn::create([
                'company_id' => $companyId,
                'branch_id' => $invoice->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $invoice->warehouse_id,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'return_reason_id' => $payload['return_reason_id'] ?? null,
                'return_no' => $returnNo,
                'status' => 'requested',
                'source' => $payload['source'] ?? 'sales',
                'return_date' => now()->toDateString(),
                'subtotal' => number_format($subtotal, 4, '.', ''),
                'tax' => number_format($taxTotal, 4, '.', ''),
                'grand_total' => number_format($subtotal + $taxTotal, 4, '.', ''),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($resolved as $r) {
                $lineNo++;
                SalesReturnLine::create(array_merge($r, [
                    'company_id' => $companyId,
                    'sales_return_id' => $return->id,
                    'line_no' => $lineNo,
                ]));
            }

            $order = null;
            $orderChanged = false;
            if ($invoice->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($invoice->sales_order_id)->lockForUpdate()->first();
                if ($order !== null && $this->stateMachine->canTransition($order->status, 'return_requested')) {
                    $order = $this->stateMachine->transition($order, 'return_requested');
                    $orderChanged = true;
                }
            }

            $this->audit->record([
                'action' => 'returns.request_created',
                'entity_type' => 'sales_return',
                'entity_id' => $return->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'return_no' => $returnNo,
                    'invoice_no' => $invoice->invoice_no,
                    'grand_total' => (float) $return->grand_total,
                    'line_count' => $lineNo,
                    'order_no' => $order?->order_no,
                    'order_status' => $order?->status,
                    'order_status_changed' => $orderChanged,
                ],
            ]);

            return $return->load('lines');
        });
    }
}
