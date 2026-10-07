<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\Actions\IssueCreditNote;
use App\Domain\Returns\Actions\ProcessRefund;
use App\Domain\Returns\Actions\ReceiveReturnedGoods;
use App\Domain\Returns\Refund;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PosSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PosReturnAction (02-38). Counter return against a POS invoice inside
 * the open session: request → receive (STK in) → credit note (ACCT) →
 * cash/bank refund (ACCT) — the full returns pipeline, never a shortcut.
 * A cash refund leaves the drawer through session.cash_out (the close
 * math already subtracts it); non-cash refunds never touch the drawer.
 * A supplied idempotency_key replays the same refund instead of
 * double-returning.
 */
class PosReturnAction
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected CreateReturnRequest $createReturnRequest,
        protected ReceiveReturnedGoods $receiveReturnedGoods,
        protected IssueCreditNote $issueCreditNote,
        protected ProcessRefund $processRefund,
    ) {}

    /**
     * @param array{
     *   invoice_id: int,
     *   lines: array<int, array{invoice_line_id: int, qty?: float|null}>,
     *   pos_session_id?: int|null,
     *   payment_method?: string,
     *   return_reason_id?: int|null,
     *   notes?: string|null,
     *   idempotency_key?: string|null,
     * } $payload
     * @return array{sales_return: SalesReturn, credit_note: ?object, refund: Refund, session: ?PosSession}
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $lines = array_values(array_filter(
            $payload['lines'] ?? [],
            fn (array $line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw new RuntimeException('POS return requires at least one line with a positive qty.');
        }

        $method = $payload['payment_method'] ?? 'cash';
        if (! in_array($method, ['cash', 'bank', 'mobile'], true)) {
            throw new RuntimeException("Unsupported POS refund method [{$method}].");
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $method, $request) {
            $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

            if ($idempotencyKey !== '') {
                $existing = Refund::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    // Replay: the money already moved — no session needed.
                    return [
                        'sales_return' => $existing->salesReturn,
                        'credit_note' => $existing->salesReturn?->creditNote,
                        'refund' => $existing,
                        'session' => PosSession::query()
                            ->where('company_id', $companyId)
                            ->where('status', 'open')
                            ->where('branch_id', $request->user()->default_branch_id)
                            ->first(),
                    ];
                }
            }

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->findOrFail($payload['invoice_id']);

            if ($invoice->invoice_type !== 'pos') {
                throw new RuntimeException(sprintf(
                    'POS return requires a POS invoice (%s is [%s]).',
                    $invoice->invoice_no,
                    $invoice->invoice_type,
                ));
            }

            $session = $this->requireOpenSession($payload, $companyId, $request);

            $salesReturn = $this->createReturnRequest->handle([
                'invoice_id' => $invoice->id,
                'lines' => $lines,
                'return_reason_id' => $payload['return_reason_id'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'source' => 'pos',
            ], $request);

            $this->receiveReturnedGoods->handle($salesReturn, [], $request);

            $creditNote = $this->issueCreditNote->handle($salesReturn->fresh(), [], $request);

            $refund = $this->processRefund->handle($salesReturn->fresh(), [
                'method' => $method,
                'narration' => "POS return {$salesReturn->return_no}",
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : 'pos-return:'.$salesReturn->id,
            ], $request);

            if ($method === 'cash') {
                $session->cash_out = number_format(
                    (float) $session->cash_out + (float) $refund->amount,
                    4,
                    '.',
                    '',
                );
                $session->save();
            }

            $this->audit->record([
                'action' => 'pos.return_processed',
                'entity_type' => 'sales_return',
                'entity_id' => $salesReturn->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'session_no' => $session->session_no,
                    'return_no' => $salesReturn->return_no,
                    'refund_no' => $refund->refund_no,
                    'credit_note_no' => $creditNote?->credit_note_no,
                    'invoice_no' => $invoice->invoice_no,
                    'amount' => (float) $refund->amount,
                    'method' => $method,
                ],
            ]);

            return [
                'sales_return' => $salesReturn->fresh(),
                'credit_note' => $creditNote,
                'refund' => $refund,
                'session' => $session->fresh(),
            ];
        });
    }

    /** The counter refund always settles through the branch's open drawer. */
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
