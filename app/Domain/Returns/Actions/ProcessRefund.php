<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Returns\Refund;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ProcessRefund (07-10). New posting linked to original invoice — never
 * rewrites history. GL via refund posting rule (mirror of receipt):
 * Dr ar (or refund expense), Cr cash. Idempotency key required for safety.
 * A returned order moves to refunded (02-27); orders that never reached
 * returned (goods were not received) keep their status.
 */
class ProcessRefund
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param array{
     *   amount?: float,
     *   method?: string,
     *   reference?: string|null,
     *   narration?: string|null,
     *   idempotency_key?: string|null,
     * } $payload
     */
    public function handle(SalesReturn $salesReturn, array $payload, Request $request): Refund
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($salesReturn, $payload, $companyId, $request) {
            $fresh = SalesReturn::query()->whereKey($salesReturn->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['credited', 'received', 'inspected', 'refunded'], true)) {
                throw new RuntimeException("Return {$fresh->return_no} cannot refund from status [{$fresh->status}].");
            }

            $idempotencyKey = $payload['idempotency_key'] ?? null;
            if (! empty($idempotencyKey)) {
                $existing = Refund::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($fresh->status === 'refunded') {
                throw new RuntimeException("Return {$fresh->return_no} is already refunded.");
            }

            $amount = round((float) ($payload['amount'] ?? $fresh->grand_total), 4);
            if ($amount <= 0) {
                throw new RuntimeException('Refund amount must be greater than zero.');
            }

            $method = $payload['method'] ?? 'cash';
            if (! in_array($method, ['cash', 'bank', 'mobile', 'adjustment'], true)) {
                throw new RuntimeException("Unsupported refund method [{$method}].");
            }

            $docType = DocumentType::query()->where('code', 'money_receipt')->first();
            $refundNo = $docType !== null
                ? 'RF-'.$this->numbering->allocate($docType->id, $request->user()->default_branch_id)
                : 'RF-'.now()->format('YmdHis').'-'.uniqid();

            $resolved = $this->rules->resolve('refund');
            $byRole = [];
            foreach ($resolved as $r) {
                $byRole[$r['role']] = $r;
            }

            $creditAccount = null;
            if ($method === 'bank' && isset($byRole['bank'])) {
                $creditAccount = $byRole['bank']['account'];
            } elseif (isset($byRole['cash'])) {
                $creditAccount = $byRole['cash']['account'];
            }

            if (! isset($byRole['ar']) || $creditAccount === null) {
                throw new RuntimeException('refund posting rule is not configured (ar/cash roles).');
            }

            $refund = Refund::create([
                'company_id' => $companyId,
                'branch_id' => $fresh->branch_id,
                'customer_id' => $fresh->customer_id,
                'invoice_id' => $fresh->invoice_id,
                'sales_return_id' => $fresh->id,
                'refund_no' => $refundNo,
                'status' => 'posted',
                'method' => $method,
                'amount' => number_format($amount, 4, '.', ''),
                'refund_date' => now()->toDateString(),
                'reference' => $payload['reference'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => null,
                'narration' => $payload['narration'] ?? "Refund for {$fresh->return_no}",
                'created_by' => $request->user()->id,
            ]);

            // New posting linked to original invoice — history never rewritten
            $entry = $this->posting->post([
                'entry_date' => $refund->refund_date->toDateString(),
                'description' => "Refund {$refundNo} against return {$fresh->return_no}",
                'journal_type' => 'receipt',
                'source_type' => 'refund',
                'source_id' => $refund->id,
                'source_event' => 'refund',
                'branch_id' => $fresh->branch_id,
                'lines' => [
                    [
                        'account_id' => $byRole['ar']['account']->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                        'party_type' => $fresh->customer_id ? 'customer' : null,
                        'party_id' => $fresh->customer_id,
                    ],
                    [
                        'account_id' => $creditAccount->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                    ],
                ],
            ], $request->user());

            $refund->journal_entry_id = $entry->id;
            $refund->save();

            $fresh->status = 'refunded';
            $fresh->save();

            $order = null;
            $orderChanged = false;
            if ($fresh->invoice?->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($fresh->invoice->sales_order_id)->lockForUpdate()->first();
                if ($order !== null && $this->stateMachine->canTransition($order->status, 'refunded')) {
                    $order = $this->stateMachine->transition($order, 'refunded');
                    $orderChanged = true;
                }
            }

            $this->audit->record([
                'action' => 'returns.refund_processed',
                'entity_type' => 'refund',
                'entity_id' => $refund->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'refund_no' => $refundNo,
                    'amount' => $amount,
                    'method' => $method,
                    'return_no' => $fresh->return_no,
                    'order_no' => $order?->order_no,
                    'order_status' => $order?->status,
                    'order_status_changed' => $orderChanged,
                ],
            ]);

            return $refund;
        });
    }
}
