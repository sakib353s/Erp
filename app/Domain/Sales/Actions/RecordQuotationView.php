<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Sales\PublicAccessLog;
use App\Domain\Sales\Quotation;
use Illuminate\Http\Request;

/**
 * RecordQuotationView (02-68): one public_access_logs row per unauthenticated
 * share-link visit, an audit event, and the truthful sent → viewed transition.
 * Runs outside the tenant context, so every write carries its own company.
 */
class RecordQuotationView
{
    public function __construct(protected AuditRecorder $audit) {}

    public function handle(Quotation $quotation, string $token, Request $request): Quotation
    {
        PublicAccessLog::create([
            'company_id' => $quotation->company_id,
            'subject_type' => 'quotation',
            'subject_id' => $quotation->id,
            'access_token' => $token,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'accessed_at' => now(),
        ]);

        if ($quotation->status === 'sent') {
            $quotation->status = 'viewed';
        }

        if ($quotation->viewed_at === null) {
            $quotation->viewed_at = now();
        }

        $quotation->save();

        $this->audit->record([
            'company_id' => $quotation->company_id,
            'action' => 'sales.quotation_viewed',
            'entity_type' => 'quotation',
            'entity_id' => $quotation->id,
            'branch_id' => $quotation->branch_id,
            'actor_type' => 'public',
            'actor_id' => null,
            'actor_label' => 'Anonymous share-link visitor',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 191),
            'after' => [
                'status' => $quotation->status,
                'viewed_at' => $quotation->viewed_at?->toIso8601String(),
                'subject_type' => 'quotation',
            ],
            'result' => 'success',
        ]);

        return $quotation;
    }
}
