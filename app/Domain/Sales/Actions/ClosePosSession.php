<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\PosSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ClosePosSession (02-33). Recomputes expected cash server-side and
 * records counted amount + variance.
 */
class ClosePosSession
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(PosSession $session, float $counted, Request $request): PosSession
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($session, $counted, $request) {
            $fresh = PosSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'open') {
                throw new RuntimeException("POS session {$fresh->session_no} is not open.");
            }

            $expected = (float) $fresh->opening_float
                + (float) $fresh->cash_sales
                + (float) $fresh->cash_in
                - (float) $fresh->cash_out;

            $fresh->status = 'closed';
            $fresh->closed_by = $request->user()->id;
            $fresh->closed_at = now();
            $fresh->closing_counted = number_format($counted, 4, '.', '');
            $fresh->expected_cash = number_format($expected, 4, '.', '');
            $fresh->variance = number_format($counted - $expected, 4, '.', '');
            $fresh->save();

            $this->audit->record([
                'action' => 'pos.session_closed',
                'entity_type' => 'pos_session',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'session_no' => $fresh->session_no,
                    'expected_cash' => $expected,
                    'closing_counted' => $counted,
                    'variance' => (float) $fresh->variance,
                ],
            ]);

            return $fresh;
        });
    }
}
