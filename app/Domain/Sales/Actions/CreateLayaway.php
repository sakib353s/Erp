<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\LayawayInstallment;
use App\Domain\Sales\LayawaySchedule;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderApprovalGate;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateLayaway (02-41): POS layaway / advance deposit.
 *
 * The counter takes a deposit against a pending sales order and records
 * the remaining balance as a dated installment schedule. Effects:
 *
 *  - STK: the order's stock is reserved immediately (source_type
 *    sales_order so confirm/issue/release all keep working; ConfirmOrder
 *    skips a second reservation because stock_reserved is already true).
 *  - ACCT: one balanced journal per deposit — Dr Cash (1110) or Bank
 *    (1120) / Cr Customer Advances (2140) via the layaway_deposit /
 *    layaway_deposit_bank posting rules. The deposit is a liability,
 *    never revenue.
 *  - Drawer: the deposit lands on the open session (cash_sales for cash,
 *    non_cash_sales otherwise) with a real pos_transactions row so X
 *    report and close math agree.
 *  - WF: when a sales_order/layaway workflow definition resolves, the
 *    order is submitted and left pending — no stock, no money — until an
 *    approved request lets the same order resume (sales_order_id).
 *
 * Honest gaps: the deposit invoice carries no goods lines (it is not a
 * supply — the delivery invoice bills the goods), installment
 * collection/delivery settlement is not yet wired, and the deposit
 * invoice is excluded from sales-revenue reports by invoice_type.
 */
class CreateLayaway
{
    public function __construct(
        protected CreateSalesOrder $createOrder,
        protected ReservationService $reservations,
        protected OrderApprovalGate $approvalGate,
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null}>,
     *   deposit_amount: float|int|string,
     *   installment_count: int,
     *   interval_days: int,
     *   payment_method: string,
     *   pos_session_id?: int|null,
     *   sales_order_id?: int|null,
     *   customer_id?: int|null,
     *   notes?: string|null,
     * } $payload
     * @return array{result: string, order: SalesOrder, schedule: ?LayawaySchedule, invoice: ?Invoice}
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $deposit = round((float) ($payload['deposit_amount'] ?? 0), 4);
        if ($deposit < 0.01) {
            throw new RuntimeException('Deposit amount must be at least 0.01.');
        }

        $count = (int) ($payload['installment_count'] ?? 0);
        if ($count < 1 || $count > 60) {
            throw new RuntimeException('Installment count must be between 1 and 60.');
        }

        $interval = (int) ($payload['interval_days'] ?? 0);
        if ($interval < 1 || $interval > 365) {
            throw new RuntimeException('Installment interval must be between 1 and 365 days.');
        }

        $method = (string) ($payload['payment_method'] ?? 'cash');
        if (! in_array($method, ['cash', 'bank', 'cheque', 'mobile'], true)) {
            throw new RuntimeException("Unsupported POS payment method [{$method}].");
        }

        return DB::transaction(function () use ($payload, $companyId, $deposit, $count, $interval, $method, $request) {
            $session = $this->requireOpenSession($payload, $companyId, $request);

            // Resume path: a held (WF-approved) layaway order continues
            // under its own id instead of creating a second order.
            $order = null;
            if (! empty($payload['sales_order_id'])) {
                $order = SalesOrder::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $payload['sales_order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($order === null || $order->status !== 'pending') {
                    throw new RuntimeException('The layaway resume order was not found in pending status.');
                }
                if (LayawaySchedule::query()->where('sales_order_id', $order->id)->exists()) {
                    throw new RuntimeException("Order {$order->order_no} already has a layaway deposit.");
                }
            } else {
                $order = $this->createOrder->handle([
                    'customer_id' => $payload['customer_id'] ?? null,
                    'warehouse_id' => (int) $session->warehouse_id,
                    'notes' => $payload['notes'] ?? null,
                    'lines' => $payload['lines'] ?? [],
                ], $request);
            }

            $orderTotal = round((float) $order->grand_total, 4);
            if ($deposit >= $orderTotal) {
                throw new RuntimeException(sprintf(
                    'Deposit %s must be less than the order total %s.',
                    number_format($deposit, 2),
                    number_format($orderTotal, 2),
                ));
            }
            $balance = round($orderTotal - $deposit, 4);

            // WF: a matching sales_order/layaway definition holds the order
            // here — no reservation, no deposit — until it is approved and
            // the same order is resumed.
            if ($this->approvalGate->pass($order, 'layaway', $request) === OrderApprovalGate::PENDING) {
                return ['result' => 'pending_approval', 'order' => $order, 'schedule' => null, 'invoice' => null];
            }

            // STK: reserve once under the order's own source so confirm,
            // issue and release all keep finding this reservation.
            if (! $order->stock_reserved) {
                $reserveLines = $order->lines->map(fn ($line) => [
                    'product_id' => (int) $line->product_id,
                    'qty' => (float) $line->qty,
                ])->all();

                $this->reservations->reserve(
                    $companyId,
                    (int) ($order->warehouse_id ?? $session->warehouse_id),
                    'sales_order',
                    $order->id,
                    $reserveLines,
                );

                $order->stock_reserved = true;
                $order->save();
            }

            // Deposit invoice: a settled, paid document for the money
            // received — no goods lines, never issuable (status paid +
            // posting_state posted both guard IssueInvoice).
            $docType = DocumentType::query()->where('code', 'invoice')->first()
                ?? abort(500, 'invoice document type is not seeded.');

            $invoiceNo = $this->numbering->allocate($docType->id, $request->user()->default_branch_id);

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'branch_id' => $session->branch_id ?? $request->user()->default_branch_id,
                'warehouse_id' => $session->warehouse_id,
                'customer_id' => $order->customer_id,
                'sales_person_id' => null,
                'sales_order_id' => $order->id,
                'document_type_id' => $docType->id,
                'pos_session_id' => $session->id,
                'invoice_no' => $invoiceNo,
                'status' => 'paid',
                'invoice_type' => 'layaway',
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'currency' => 'BDT',
                'subtotal' => number_format($deposit, 4, '.', ''),
                'doc_discount' => 0,
                'taxable_base' => number_format($deposit, 4, '.', ''),
                'tax' => 0,
                'shipping' => 0,
                'rounding' => 0,
                'grand_total' => number_format($deposit, 4, '.', ''),
                'paid_amount' => number_format($deposit, 4, '.', ''),
                'due_amount' => 0,
                'tax_applicable' => false,
                'notes' => sprintf(
                    'Layaway deposit against %s — balance %s over %d installment(s).',
                    $order->order_no,
                    number_format($balance, 2),
                    $count,
                ),
                'created_by' => $request->user()->id,
            ]);

            // ACCT: Dr cash/bank, Cr customer advances — the deposit is a
            // liability, never revenue.
            $eventType = $method === 'cash' ? 'layaway_deposit' : 'layaway_deposit_bank';
            $resolved = $this->rules->resolve($eventType);
            $lines = array_map(fn (array $rule) => [
                'account_id' => $rule['account']->id,
                'dc' => $rule['side'],
                'amount' => $deposit,
            ], $resolved);

            $entry = $this->posting->post([
                'entry_date' => now()->toDateString(),
                'description' => sprintf('Layaway deposit %s (%s)', $invoiceNo, $order->order_no),
                'narration' => sprintf(
                    'Deposit %s — balance %s over %d installment(s)',
                    number_format($deposit, 2),
                    number_format($balance, 2),
                    $count,
                ),
                'journal_type' => 'layaway',
                'source_type' => 'pos_session',
                'source_id' => $session->id,
                'source_event' => 'layaway_deposit',
                'branch_id' => $session->branch_id,
                'lines' => $lines,
            ], $request->user());

            $invoice->posting_state = 'posted';
            $invoice->journal_entry_id = $entry->id;
            $invoice->save();

            // Drawer: the deposit is money through the counter — a real
            // pos_transactions row plus the matching session counter.
            $transaction = PosTransaction::create([
                'company_id' => $companyId,
                'pos_session_id' => $session->id,
                'invoice_id' => $invoice->id,
                'customer_id' => $order->customer_id,
                'status' => 'completed',
                'sync_state' => 'synced',
                'total' => number_format($deposit, 4, '.', ''),
                'payment_method' => $method,
                'tendered' => number_format($deposit, 4, '.', ''),
                'change_due' => 0,
                'sold_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            if ($method === 'cash') {
                $session->cash_sales = number_format((float) $session->cash_sales + $deposit, 4, '.', '');
            } else {
                $session->non_cash_sales = number_format((float) $session->non_cash_sales + $deposit, 4, '.', '');
            }
            $session->save();

            // Schedule: equal installments, the last one absorbs rounding.
            $schedule = LayawaySchedule::create([
                'company_id' => $companyId,
                'branch_id' => $session->branch_id,
                'sales_order_id' => $order->id,
                'deposit_invoice_id' => $invoice->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id ?? $session->warehouse_id,
                'pos_session_id' => $session->id,
                'order_total' => number_format($orderTotal, 4, '.', ''),
                'deposit_amount' => number_format($deposit, 4, '.', ''),
                'balance_amount' => number_format($balance, 4, '.', ''),
                'installment_count' => $count,
                'interval_days' => $interval,
                'first_due_on' => now()->addDays($interval)->toDateString(),
                'status' => LayawaySchedule::STATUS_OPEN,
                'created_by' => $request->user()->id,
            ]);

            $base = round($balance / $count, 4);
            $allocated = 0.0;
            for ($i = 0; $i < $count; $i++) {
                $amount = $i === $count - 1
                    ? round($balance - $allocated, 4)
                    : $base;
                $allocated = round($allocated + $amount, 4);

                LayawayInstallment::create([
                    'company_id' => $companyId,
                    'layaway_schedule_id' => $schedule->id,
                    'line_no' => $i + 1,
                    'due_on' => now()->addDays($interval * ($i + 1))->toDateString(),
                    'amount' => number_format($amount, 4, '.', ''),
                    'status' => LayawayInstallment::STATUS_PENDING,
                ]);
            }

            $this->audit->record([
                'action' => 'pos.layaway_created',
                'entity_type' => 'layaway_schedule',
                'entity_id' => $schedule->id,
                'actor_id' => $request->user()->id,
                'branch_id' => $session->branch_id,
                'after' => [
                    'order_no' => $order->order_no,
                    'invoice_no' => $invoiceNo,
                    'deposit_amount' => $deposit,
                    'balance_amount' => $balance,
                    'installment_count' => $count,
                    'interval_days' => $interval,
                    'payment_method' => $method,
                    'entry_no' => $entry->entry_no,
                    'transaction_total' => (float) $transaction->total,
                ],
            ]);

            return [
                'result' => 'created',
                'order' => $order->fresh(),
                'schedule' => $schedule->fresh(),
                'invoice' => $invoice->fresh(),
            ];
        });
    }

    /** The deposit is money through this branch's open drawer. */
    protected function requireOpenSession(array $payload, int $companyId, Request $request): PosSession
    {
        $sessionId = (int) ($payload['pos_session_id'] ?? 0);
        $query = PosSession::query()
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->lockForUpdate();

        $session = $sessionId > 0
            ? $query->whereKey($sessionId)->first()
            : $query->where('branch_id', $request->user()->default_branch_id)->first();

        if ($session === null) {
            throw new RuntimeException('No open POS session for this branch.');
        }

        return $session;
    }
}
