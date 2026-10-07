<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AcceptQuotation (02-69). DOC status transition only — no stock/GL.
 * Allowed from draft/sent/viewed (not converted/declined/expired).
 */
class AcceptQuotation
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Quotation $quotation, Request $request): Quotation
    {
        $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($quotation, $request) {
            $fresh = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['draft', 'sent', 'viewed'], true)) {
                throw new RuntimeException(sprintf(
                    'Quotation %s cannot be accepted from status [%s].',
                    $fresh->quote_no,
                    $fresh->status,
                ));
            }

            $fresh->status = 'accepted';
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.quotation_accepted',
                'entity_type' => 'quotation',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'quote_no' => $fresh->quote_no,
                    'status' => 'accepted',
                    'grand_total' => (float) $fresh->grand_total,
                ],
            ]);

            return $fresh;
        });
    }
}
