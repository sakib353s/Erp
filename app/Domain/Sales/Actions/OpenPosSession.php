<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\PosSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OpenPosSession (02-32). One open session per company/branch at a time
 * (row lock on open check).
 */
class OpenPosSession
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(array $payload, Request $request): PosSession
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($payload, $companyId, $request) {
            $open = PosSession::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $request->user()->default_branch_id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw new RuntimeException("POS session {$open->session_no} is already open.");
            }

            $docType = DocumentType::query()->where('code', 'money_receipt')->first();
            $sessionNo = $docType !== null
                ? 'POS-'.$this->numbering->allocate($docType->id, $request->user()->default_branch_id)
                : 'POS-'.now()->format('YmdHis').'-'.uniqid();

            $session = PosSession::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'warehouse_id' => $payload['warehouse_id'] ?? null,
                'opened_by' => $request->user()->id,
                'session_no' => $sessionNo,
                'display_code' => Str::upper(Str::random(8)),
                'status' => 'open',
                'opened_at' => now(),
                'opening_float' => number_format((float) ($payload['opening_float'] ?? 0), 4, '.', ''),
                'expected_cash' => number_format((float) ($payload['opening_float'] ?? 0), 4, '.', ''),
            ]);

            $this->audit->record([
                'action' => 'pos.session_opened',
                'entity_type' => 'pos_session',
                'entity_id' => $session->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'session_no' => $sessionNo,
                    'opening_float' => (float) $session->opening_float,
                ],
            ]);

            return $session;
        });
    }
}
