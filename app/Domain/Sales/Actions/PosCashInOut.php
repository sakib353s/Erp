<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\PosSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PosCashInOut (02-45). Drawer movement inside the open session: Cash In
 * adds to the drawer, Cash Out removes from it. Both feed the close math
 * (expected = opening + cash_sales + cash_in − cash_out) and post one
 * balanced journal entry resolved through the pos_cash_in / pos_cash_out
 * posting rules — a Cash in Hand ↔ Bank transfer, so the ledger never
 * fabricates income or expense for an internal drawer movement. Every
 * movement carries an audited reason.
 */
class PosCashInOut
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
    ) {}

    /**
     * @param array{
     *   direction: string,
     *   amount: float|int|string,
     *   reason: string,
     *   pos_session_id?: int|null,
     * } $payload
     */
    public function handle(array $payload, Request $request): PosSession
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $direction = (string) ($payload['direction'] ?? '');
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new RuntimeException('Cash direction must be [in] or [out].');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 4);
        if ($amount <= 0) {
            throw new RuntimeException('Cash movement amount must be greater than zero.');
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('A cash movement reason is required.');
        }

        return DB::transaction(function () use ($payload, $companyId, $direction, $amount, $reason, $request) {
            $session = $this->requireOpenSession($payload, $companyId, $request);

            if ($direction === 'in') {
                $session->cash_in = number_format((float) $session->cash_in + $amount, 4, '.', '');
            } else {
                $session->cash_out = number_format((float) $session->cash_out + $amount, 4, '.', '');
            }
            $session->save();

            $eventType = $direction === 'in' ? 'pos_cash_in' : 'pos_cash_out';
            $resolved = $this->rules->resolve($eventType);

            $lines = array_map(fn (array $rule) => [
                'account_id' => $rule['account']->id,
                'dc' => $rule['side'],
                'amount' => $amount,
            ], $resolved);

            $entry = $this->posting->post([
                'entry_date' => now()->toDateString(),
                'description' => sprintf('POS cash %s: %s', $direction, $reason),
                'narration' => $reason,
                'journal_type' => 'cash_movement',
                'source_type' => 'pos_session',
                'source_id' => $session->id,
                'source_event' => $eventType,
                'branch_id' => $session->branch_id,
                'lines' => $lines,
            ], $request->user());

            $this->audit->record([
                'action' => 'pos.cash_moved',
                'entity_type' => 'pos_session',
                'entity_id' => $session->id,
                'actor_id' => $request->user()->id,
                'branch_id' => $session->branch_id,
                'amount' => $amount,
                'after' => [
                    'direction' => $direction,
                    'amount' => $amount,
                    'reason' => $reason,
                    'entry_no' => $entry->entry_no,
                    'cash_in' => (float) $session->cash_in,
                    'cash_out' => (float) $session->cash_out,
                ],
            ]);

            return $session->fresh();
        });
    }

    /** Drawer movements only count against this branch's open session. */
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
