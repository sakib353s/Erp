<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\CommissionCalculation;
use App\Domain\Sales\CommissionPayment;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PayCommission (02-81 payment). When a workflow definition exists for
 * entity commission_payment / action pay, submits for approval and does
 * NOT post GL until approved. Otherwise posts immediately: settle via
 * commission_payment rule (Dr commission payable or expense, Cr cash).
 * Notifications: no employee-facing send (NOT to employee in this slice).
 */
class PayCommission
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected WorkflowEngine $workflow,
    ) {}

    /**
     * @param array{
     *   amount?: float,
     *   method?: string,
     *   payment_date?: string,
     *   reference?: string|null,
     *   narration?: string|null,
     *   idempotency_key?: string|null,
     * } $payload
     */
    public function handle(CommissionCalculation $calculation, array $payload, Request $request): CommissionPayment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($calculation, $payload, $companyId, $request) {
            $fresh = CommissionCalculation::query()
                ->whereKey($calculation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $idempotencyKey = $payload['idempotency_key'] ?? null;
            if (! empty($idempotencyKey)) {
                $existing = CommissionPayment::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $this->maybeFinalize($existing, $request);
                }
            }

            if ($fresh->status === 'paid') {
                throw new RuntimeException(
                    'Commission calculation is already paid.'
                );
            }

            if (! in_array($fresh->status, ['accrued', 'pending_approval'], true)) {
                throw new RuntimeException(
                    "Commission calculation status [{$fresh->status}] is not payable."
                );
            }

            $amount = round((float) ($payload['amount'] ?? $fresh->commission_amount), 4);
            if ($amount <= 0) {
                throw new RuntimeException('Commission payment amount must be greater than zero.');
            }
            if ($amount > (float) $fresh->commission_amount + 0.0001) {
                throw new RuntimeException('Payment exceeds earned commission amount.');
            }

            $method = $payload['method'] ?? 'cash';
            if (! in_array($method, ['cash', 'bank'], true)) {
                throw new RuntimeException("Unsupported commission payment method [{$method}].");
            }

            // Reuse open payment for this calculation if still pending approval
            $payment = CommissionPayment::query()
                ->where('company_id', $companyId)
                ->where('commission_calculation_id', $fresh->id)
                ->whereIn('status', ['pending_approval', 'paid'])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($payment !== null && $payment->status === 'paid') {
                throw new RuntimeException("Commission payment {$payment->payment_no} is already paid.");
            }

            if ($payment === null) {
                $payment = CommissionPayment::create([
                    'company_id' => $companyId,
                    'branch_id' => $fresh->branch_id,
                    'employee_id' => $fresh->employee_id,
                    'commission_calculation_id' => $fresh->id,
                    'payment_no' => 'CP-'.now()->format('YmdHis').'-'.substr(uniqid(), -6),
                    'status' => 'pending_approval',
                    'method' => $method,
                    'amount' => number_format($amount, 4, '.', ''),
                    'payment_date' => $payload['payment_date'] ?? now()->toDateString(),
                    'reference' => $payload['reference'] ?? null,
                    'narration' => $payload['narration'] ?? "Commission payment for {$fresh->period_type} {$fresh->period_start}",
                    'idempotency_key' => $idempotencyKey,
                    'journal_entry_id' => null,
                    'approval_request_id' => null,
                    'created_by' => $request->user()->id,
                ]);
            } else {
                $payment->fill([
                    'method' => $method,
                    'amount' => number_format($amount, 4, '.', ''),
                    'payment_date' => $payload['payment_date'] ?? $payment->payment_date?->toDateString() ?? now()->toDateString(),
                    'reference' => $payload['reference'] ?? $payment->reference,
                    'narration' => $payload['narration'] ?? $payment->narration,
                ]);
                $payment->save();
            }

            // Workflow: submit if definition exists and no open/approved request yet
            if ($payment->approval_request_id === null) {
                $approval = $this->workflow->submit([
                    'entity_type' => 'commission_payment',
                    'entity_id' => $payment->id,
                    'action' => 'pay',
                    'subject' => "Commission {$payment->payment_no}",
                    'branch_id' => $payment->branch_id ?? $request->user()->default_branch_id,
                    'submitted_by' => $request->user(),
                    'snapshot' => [
                        'employee_id' => $payment->employee_id,
                        'calculation_id' => $fresh->id,
                        'amount' => $amount,
                    ],
                    'amount' => $amount,
                    'currency' => 'BDT',
                    'reference_no' => $payment->payment_no,
                    'context' => [
                        'amount' => $amount,
                        'branch_id' => $payment->branch_id,
                        'currency' => 'BDT',
                    ],
                ]);

                if ($approval !== null) {
                    $payment->approval_request_id = $approval->id;
                    $payment->status = 'pending_approval';
                    $payment->save();
                    $fresh->status = 'pending_approval';
                    $fresh->save();

                    $this->audit->record([
                        'action' => 'sales.commission_payment_submitted',
                        'entity_type' => 'commission_payment',
                        'entity_id' => $payment->id,
                        'actor_id' => $request->user()->id,
                        'after' => [
                            'payment_no' => $payment->payment_no,
                            'amount' => $amount,
                            'approval_request_id' => $approval->id,
                        ],
                    ]);

                    return $payment;
                }
            }

            return $this->finalizePayment($payment, $fresh, $request);
        });
    }

    /**
     * Finalize a pending payment once its approval is approved
     * (or when no workflow applies).
     */
    protected function finalizePayment(
        CommissionPayment $payment,
        CommissionCalculation $fresh,
        Request $request,
    ): CommissionPayment {
        return DB::transaction(function () use ($payment, $fresh, $request) {
            if ($payment->status === 'paid') {
                return $payment;
            }

            if ($payment->approval_request_id !== null) {
                $approval = ApprovalRequest::query()->find($payment->approval_request_id);
                if ($approval === null || $approval->status !== 'approved') {
                    throw new RuntimeException('Commission payment approval is not complete.');
                }
            }

            $amount = (float) $payment->amount;
            // Accrued → settle liability; else direct expense cash-out
            $eventType = $fresh->journal_entry_id !== null
                ? 'commission_payment'
                : 'commission_direct';
            $resolved = $this->rules->resolve($eventType);
            $byRole = [];
            foreach ($resolved as $r) {
                $byRole[$r['role']] = $r;
            }

            $debit = $byRole['commission_payable']['account']
                ?? $byRole['commission_expense']['account']
                ?? null;
            $credit = $byRole['cash']['account']
                ?? $byRole['bank']['account']
                ?? null;

            if ($debit === null || $credit === null) {
                throw new RuntimeException(
                    "{$eventType} posting rule is not configured (payable/expense + cash roles)."
                );
            }

            $entry = $this->posting->post([
                'entry_date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
                'description' => "Commission payment {$payment->payment_no} to {$fresh->employee_id}",
                'journal_type' => 'commission',
                'source_type' => 'commission_payment',
                'source_id' => $payment->id,
                'source_event' => 'commission_payment',
                'branch_id' => $payment->branch_id,
                'lines' => [
                    [
                        'account_id' => $debit->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                    ],
                    [
                        'account_id' => $credit->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                    ],
                ],
            ], $request->user());

            $payment->journal_entry_id = $entry->id;
            $payment->status = 'paid';
            $payment->save();

            $fresh->status = 'paid';
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.commission_paid',
                'entity_type' => 'commission_payment',
                'entity_id' => $payment->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'payment_no' => $payment->payment_no,
                    'amount' => $amount,
                    'method' => $payment->method,
                    'journal_entry_id' => $entry->id,
                    'calculation_status' => 'paid',
                ],
            ]);

            return $payment;
        });
    }

    /**
     * If payment is pending_approval with an approved request, post GL now.
     * Unapproved requests refuse finalize (caller sees the RuntimeException).
     */
    protected function maybeFinalize(CommissionPayment $payment, Request $request): CommissionPayment
    {
        if ($payment->status === 'paid') {
            return $payment;
        }

        if ($payment->approval_request_id === null) {
            return $payment;
        }

        $approval = ApprovalRequest::query()->find($payment->approval_request_id);
        if ($approval !== null && $approval->status === 'approved') {
            $fresh = CommissionCalculation::query()->findOrFail($payment->commission_calculation_id);

            return $this->finalizePayment($payment, $fresh, $request);
        }

        throw new RuntimeException('Commission payment approval is not complete.');
    }
}
