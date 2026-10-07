<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\CodReconciliation;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ReconcileCod (02-97): match the cash riders recorded as collected
 * against the remittance handed to the office.
 *
 * GL posts once through the cod_remittance rule — Dr cash (remitted)
 * [Dr cash over/short when short], Cr AR (cash_total) [Cr other income
 * when over] — and every collection settles its own order's invoice
 * (money receipt + allocation + paid/due), so the AR subledger follows
 * the control account. Workflow first when a definition exists for
 * entity cod_reconciliation / action reconcile: nothing posts until
 * the approval is approved (re-submit the same collections after
 * approval to finalize; unapproved requests refuse GL).
 *
 * No stock, STK, or customer notification ever happens here.
 */
class ReconcileCod
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected WorkflowEngine $workflow,
    ) {}

    /**
     * @param array{
     *   collection_ids: array<int, int>,
     *   remitted_amount: float|int|string,
     *   remitted_at?: string|null,
     *   reference?: ?string,
     *   notes?: ?string,
     * } $payload
     */
    public function handle(array $payload, Request $request): CodReconciliation
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($payload, $companyId, $request) {
            $ids = array_values(array_unique(array_map('intval', $payload['collection_ids'] ?? [])));
            if ($ids === []) {
                throw new RuntimeException('Select at least one COD collection to reconcile.');
            }

            $collections = RiderCodCollection::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($collections->count() !== count($ids)) {
                throw new RuntimeException('Some COD collections were not found for this company.');
            }

            $riderId = (int) $collections->first()->rider_employee_id;
            if ($collections->contains(
                fn (RiderCodCollection $c) => (int) $c->rider_employee_id !== $riderId,
            )) {
                throw new RuntimeException('Selected COD collections belong to different riders.');
            }

            $marked = $collections->filter(
                fn (RiderCodCollection $c) => $c->cod_reconciliation_id !== null,
            );
            if ($marked->isNotEmpty()) {
                return $this->resume($companyId, $marked, $request);
            }

            $cashTotal = round((float) $collections->sum('amount'), 2);
            $remitted = round((float) ($payload['remitted_amount'] ?? 0), 2);
            if ($remitted < 0) {
                throw new RuntimeException('Remitted amount cannot be negative.');
            }

            $row = CodReconciliation::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'rider_employee_id' => $riderId,
                'remitted_amount' => number_format($remitted, 2, '.', ''),
                'cash_total' => number_format($cashTotal, 2, '.', ''),
                'variance' => number_format($remitted - $cashTotal, 2, '.', ''),
                'status' => CodReconciliation::STATUS_PENDING,
                'remitted_at' => $payload['remitted_at'] ?? now(),
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]);

            RiderCodCollection::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->update(['cod_reconciliation_id' => $row->id]);

            $row->loadMissing('rider');

            $approval = $this->workflow->submit([
                'entity_type' => 'cod_reconciliation',
                'entity_id' => $row->id,
                'action' => 'reconcile',
                'subject' => sprintf(
                    'COD remittance %s (%d collections)',
                    $row->rider?->full_name ?? "employee {$riderId}",
                    count($ids),
                ),
                'branch_id' => $row->branch_id,
                'submitted_by' => $request->user(),
                'snapshot' => [
                    'rider_employee_id' => $riderId,
                    'cash_total' => $cashTotal,
                    'remitted_amount' => $remitted,
                    'variance' => round($remitted - $cashTotal, 2),
                    'collections' => count($ids),
                ],
                'amount' => $remitted,
                'currency' => 'BDT',
                'reference_no' => $row->reference,
                'context' => [
                    'amount' => $remitted,
                    'branch_id' => $row->branch_id,
                    'currency' => 'BDT',
                ],
            ]);

            if ($approval !== null) {
                $row->update(['approval_request_id' => $approval->id]);

                $this->audit->record([
                    'action' => 'sales.cod_reconciliation_submitted',
                    'entity_type' => 'cod_reconciliation',
                    'entity_id' => $row->id,
                    'actor_id' => $request->user()->id,
                    'after' => [
                        'rider_employee_id' => $riderId,
                        'collections' => count($ids),
                        'cash_total' => $cashTotal,
                        'remitted_amount' => $remitted,
                        'variance' => round($remitted - $cashTotal, 2),
                        'approval_request_id' => $approval->id,
                    ],
                ]);

                return $row;
            }

            return $this->finalize($row, $request);
        });
    }

    /**
     * Re-submitting collections that already belong to a reconciliation:
     * finalize when the approval went through, otherwise refuse GL with
     * the truthful reason (nothing posts while approval is incomplete).
     *
     * @param  Collection<int, RiderCodCollection>  $marked
     */
    protected function resume(int $companyId, Collection $marked, Request $request): CodReconciliation
    {
        $rowIds = $marked->pluck('cod_reconciliation_id')->unique()->values();
        if ($rowIds->count() !== 1) {
            throw new RuntimeException('Selected COD collections belong to different reconciliations.');
        }

        $row = CodReconciliation::query()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail((int) $rowIds->first());

        if ($row->isReconciled()) {
            throw new RuntimeException("Reconciliation {$row->id} is already reconciled.");
        }

        $approval = $row->approval_request_id !== null
            ? ApprovalRequest::query()->find($row->approval_request_id)
            : null;

        if ($approval === null || $approval->status !== 'approved') {
            throw new RuntimeException('COD reconciliation approval is not complete.');
        }

        return $this->finalize($row, $request);
    }

    /**
     * Post cod_remittance GL and settle every collection's invoice
     * (payment + allocation + paid/due), then mark the row reconciled.
     */
    protected function finalize(CodReconciliation $row, Request $request): CodReconciliation
    {
        if ($row->isReconciled()) {
            return $row;
        }

        $collections = RiderCodCollection::query()
            ->where('company_id', $row->company_id)
            ->where('cod_reconciliation_id', $row->id)
            ->orderBy('id')
            ->get();

        if ($collections->isEmpty()) {
            throw new RuntimeException('Reconciliation has no COD collections to settle.');
        }

        $cashTotal = round((float) $collections->sum('amount'), 2);
        $remitted = round((float) $row->remitted_amount, 2);
        $shortage = round(max(0, $cashTotal - $remitted), 2);
        $overage = round(max(0, $remitted - $cashTotal), 2);

        $resolved = $this->rules->resolve('cod_remittance');
        $byRole = [];
        foreach ($resolved as $r) {
            $byRole[$r['role']] = $r;
        }
        foreach (['cash', 'ar', 'shortage', 'overage'] as $role) {
            if (! isset($byRole[$role]['account'])) {
                throw new RuntimeException("cod_remittance posting rule is not configured ({$role} role).");
            }
        }

        $plan = $this->planSettlements($collections, $row);

        $lines = [];
        if ($remitted > 0) {
            $lines[] = [
                'account_id' => $byRole['cash']['account']->id,
                'dc' => 'debit',
                'amount' => $remitted,
            ];
        }
        if ($shortage > 0) {
            $lines[] = [
                'account_id' => $byRole['shortage']['account']->id,
                'dc' => 'debit',
                'amount' => $shortage,
            ];
        }
        $lines[] = [
            'account_id' => $byRole['ar']['account']->id,
            'dc' => 'credit',
            'amount' => $cashTotal,
        ];
        if ($overage > 0) {
            $lines[] = [
                'account_id' => $byRole['overage']['account']->id,
                'dc' => 'credit',
                'amount' => $overage,
            ];
        }

        $entry = $this->posting->post([
            'entry_date' => $row->remitted_at?->toDateString() ?? now()->toDateString(),
            'description' => sprintf(
                'COD remittance %s%s',
                $row->rider?->full_name ?? "employee {$row->rider_employee_id}",
                $row->reference !== null ? " ref {$row->reference}" : '',
            ),
            'narration' => $row->notes,
            'journal_type' => 'receipt',
            'source_type' => 'cod_reconciliation',
            'source_id' => $row->id,
            'source_event' => 'cod_remittance',
            'branch_id' => $row->branch_id,
            'lines' => $lines,
        ], $request->user());

        foreach ($plan as $item) {
            $this->settleInvoice($row, $item, $entry->id, $byRole['cash']['account']->id, $request);
        }

        $row->update([
            'status' => CodReconciliation::STATUS_RECONCILED,
            'journal_entry_id' => $entry->id,
            'reconciled_by' => $request->user()->id,
            'reconciled_at' => now(),
        ]);

        $this->audit->record([
            'action' => 'sales.cod_reconciled',
            'entity_type' => 'cod_reconciliation',
            'entity_id' => $row->id,
            'actor_id' => $request->user()->id,
            'after' => [
                'rider_employee_id' => $row->rider_employee_id,
                'collections' => count($plan),
                'cash_total' => $cashTotal,
                'remitted_amount' => $remitted,
                'variance' => round($remitted - $cashTotal, 2),
                'journal_entry_id' => $entry->id,
                'payments' => count($plan),
                'allocated' => round((float) collect($plan)->sum(
                    fn (array $item) => $item['amount'],
                ), 2),
            ],
        ]);

        return $row->fresh();
    }

    /**
     * Every collection must settle against its own order's open issued
     * invoice (AR subledger follows the control account) — refuse with
     * the truthful reason before anything posts.
     *
     * @param  Collection<int, RiderCodCollection>  $collections
     * @return array<int, array{collection: RiderCodCollection, invoice: Invoice, amount: float}>
     */
    protected function planSettlements(Collection $collections, CodReconciliation $row): array
    {
        $plan = [];
        $usedDue = [];

        foreach ($collections as $collection) {
            $order = $collection->assignment?->shipment?->order;
            if ($order === null) {
                throw new RuntimeException(
                    "COD collection {$collection->id} has no order — cannot settle against an invoice.",
                );
            }

            $invoices = Invoice::query()
                ->where('company_id', $row->company_id)
                ->where('sales_order_id', $order->id)
                ->orderBy('id')
                ->get();

            $invoice = $invoices->first(
                fn (Invoice $i) => in_array($i->status, ['issued', 'partial'], true),
            );

            if ($invoice === null) {
                $first = $invoices->first();
                if ($first === null) {
                    throw new RuntimeException(
                        "Order {$order->order_no} has no invoice — issue it before reconciling COD.",
                    );
                }
                if ($first->status === 'paid') {
                    throw new RuntimeException(
                        "Invoice {$first->invoice_no} is already fully paid — collection {$collection->id} cannot be allocated.",
                    );
                }
                throw new RuntimeException(
                    "Invoice {$first->invoice_no} is not issued (status [{$first->status}]) — issue it before reconciling COD.",
                );
            }

            $amount = round((float) $collection->amount, 2);
            $dueLeft = round((float) $invoice->due_amount - ($usedDue[$invoice->id] ?? 0), 4);

            if ($amount > $dueLeft + 0.0001) {
                if ($dueLeft <= 0.0001) {
                    throw new RuntimeException(
                        "Invoice {$invoice->invoice_no} is already fully paid — collection {$collection->id} cannot be allocated.",
                    );
                }
                throw new RuntimeException(sprintf(
                    'Collection %.2f exceeds due %.2f on invoice %s.',
                    $amount,
                    $dueLeft,
                    $invoice->invoice_no,
                ));
            }

            $usedDue[$invoice->id] = ($usedDue[$invoice->id] ?? 0) + $amount;
            $plan[] = [
                'collection' => $collection,
                'invoice' => $invoice,
                'amount' => $amount,
            ];
        }

        return $plan;
    }

    /**
     * One money receipt per collection against the shared cod_remittance
     * journal, with the allocation that settles the invoice's subledger.
     *
     * @param  array{collection: RiderCodCollection, invoice: Invoice, amount: float}  $item
     */
    protected function settleInvoice(
        CodReconciliation $row,
        array $item,
        int $journalEntryId,
        int $cashAccountId,
        Request $request,
    ): void {
        $invoice = $item['invoice'];
        $collection = $item['collection'];
        $amount = $item['amount'];

        $docType = DocumentType::query()->where('code', 'money_receipt')->first()
            ?? abort(500, 'money_receipt document type is not seeded.');

        $receiptNo = $this->numbering->allocate(
            $docType->id,
            $request->user()->default_branch_id,
        );

        $payment = Payment::create([
            'company_id' => $row->company_id,
            'branch_id' => $invoice->branch_id,
            'customer_id' => $invoice->customer_id,
            'payment_method_id' => null,
            'account_id' => $cashAccountId,
            'receipt_no' => $receiptNo,
            'direction' => 'in',
            'method' => 'cash',
            'amount' => number_format($amount, 4, '.', ''),
            'status' => 'posted',
            'paid_at' => $row->remitted_at?->toDateString() ?? now()->toDateString(),
            'reference' => $row->reference,
            'narration' => "COD reconciliation {$row->id}",
            'idempotency_key' => "cod-recon-{$row->id}-{$collection->id}",
            'journal_entry_id' => $journalEntryId,
            'created_by' => $request->user()->id,
        ]);

        PaymentAllocation::create([
            'company_id' => $row->company_id,
            'payment_id' => $payment->id,
            'allocatable_type' => Invoice::class,
            'allocatable_id' => $invoice->id,
            'amount' => number_format($amount, 4, '.', ''),
            'branch_id' => $invoice->branch_id,
            'created_by' => $request->user()->id,
        ]);

        $newPaid = round((float) $invoice->paid_amount + $amount, 4);
        $newDue = round(max(0, (float) $invoice->grand_total - $newPaid), 4);

        $invoice->paid_amount = number_format($newPaid, 4, '.', '');
        $invoice->due_amount = number_format($newDue, 4, '.', '');
        $invoice->status = $newDue <= 0.0001 ? 'paid' : 'partial';
        $invoice->save();
    }
}
