<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DeclineQuotation (02-70). DOC status transition with required reason.
 * Allowed from draft/sent/viewed/accepted (not converted).
 */
class DeclineQuotation
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Quotation $quotation, string $reason, Request $request): Quotation
    {
        $this->context->companyId() ?? abort(500, 'No company context.');

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Decline reason is required.');
        }

        return DB::transaction(function () use ($quotation, $reason, $request) {
            $fresh = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['draft', 'sent', 'viewed', 'accepted'], true)) {
                throw new RuntimeException(sprintf(
                    'Quotation %s cannot be declined from status [%s].',
                    $fresh->quote_no,
                    $fresh->status,
                ));
            }

            $fresh->status = 'declined';
            $fresh->notes = trim(($fresh->notes !== null && $fresh->notes !== '' ? $fresh->notes."\n" : '')."[declined] {$reason}");
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.quotation_declined',
                'entity_type' => 'quotation',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'reason' => $reason,
                'after' => [
                    'quote_no' => $fresh->quote_no,
                    'status' => 'declined',
                ],
            ]);

            return $fresh;
        });
    }
}
