<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\QuotationLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ReviseQuotation (02-72). New revision row; original immutable.
 * revision_of chains to parent; parent status stays as-is (or draft).
 */
class ReviseQuotation
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Quotation $quotation, array $payload, Request $request): Quotation
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (in_array($quotation->status, ['converted'], true)) {
            throw new RuntimeException("Quotation {$quotation->quote_no} is converted and cannot be revised.");
        }

        return DB::transaction(function () use ($quotation, $payload, $companyId, $request) {
            $fresh = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            // Walk to root to chain from latest sibling count
            $rootId = $fresh->revision_of ?? $fresh->id;
            $maxRevision = (int) Quotation::query()
                ->where('company_id', $companyId)
                ->where(function ($q) use ($rootId) {
                    $q->where('id', $rootId)->orWhere('revision_of', $rootId);
                })
                ->max('revision');

            $docType = DocumentType::query()->where('code', 'quotation')->first()
                ?? abort(500, 'quotation document type is not seeded.');

            $quoteNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $linesPayload = $payload['lines'] ?? null;
            if ($linesPayload === null || $linesPayload === []) {
                $linesPayload = $fresh->lines->map(fn ($l) => [
                    'product_id' => $l->product_id,
                    'qty' => $l->qty,
                    'unit_price' => $l->unit_price,
                    'discount' => $l->discount,
                    'description' => $l->description,
                ])->all();
            }

            $lineNo = 0;
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $createdLines = [];

            foreach ($linesPayload as $line) {
                $lineNo++;
                $qty = (float) ($line['qty'] ?? 0);
                $unit = round((float) ($line['unit_price'] ?? 0), 4);
                $discount = round((float) ($line['discount'] ?? 0), 4);
                $tax = round((float) ($line['tax'] ?? 0), 4);
                $net = round($qty * $unit - $discount, 4);
                $lineTotal = round($net + $tax, 4);
                $subtotal += round($qty * $unit, 4);
                $discountTotal += $discount;
                $taxTotal += $tax;
                $createdLines[] = [
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'discount' => $discount,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            $grand = round($subtotal - $discountTotal + $taxTotal + (float) ($fresh->shipping ?? 0), 4);

            $revision = Quotation::create([
                'company_id' => $companyId,
                'branch_id' => $fresh->branch_id,
                'customer_id' => $payload['customer_id'] ?? $fresh->customer_id,
                'quote_no' => $quoteNo,
                'revision' => $maxRevision + 1,
                'revision_of' => $rootId,
                'status' => 'draft',
                'quote_date' => $payload['quote_date'] ?? now()->toDateString(),
                'valid_until' => $payload['valid_until'] ?? $fresh->valid_until,
                'currency' => $fresh->currency,
                'subtotal' => number_format($subtotal, 4, '.', ''),
                'discount' => number_format($discountTotal, 4, '.', ''),
                'tax' => number_format($taxTotal, 4, '.', ''),
                'shipping' => $fresh->shipping,
                'grand_total' => number_format($grand, 4, '.', ''),
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'notes' => $payload['notes'] ?? $fresh->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($createdLines as $cl) {
                QuotationLine::create(array_merge($cl, [
                    'company_id' => $companyId,
                    'quotation_id' => $revision->id,
                ]));
            }

            $this->audit->record([
                'action' => 'sales.quotation_revised',
                'entity_type' => 'quotation',
                'entity_id' => $revision->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'quote_no' => $quoteNo,
                    'revision' => (int) $revision->revision,
                    'revision_of' => $rootId,
                    'parent_quote_no' => $fresh->quote_no,
                ],
            ]);

            return $revision->load('lines');
        });
    }
}
