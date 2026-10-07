<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Http\Request;

/**
 * UpdateAccount (09-04). History-breaking changes (type/code/reparent
 * on accounts with journal lines) are rejected by AccountService.
 */
class UpdateAccount
{
    public function __construct(
        protected AccountService $accounts,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Account $account, array $data, Request $request): Account
    {
        $before = [
            'name' => $account->name,
            'is_active' => $account->is_active,
            'parent_id' => $account->parent_id,
        ];

        $updated = $this->accounts->update($account, $data);

        $this->audit->record([
            'action' => 'accounting.account_updated',
            'entity_type' => 'account',
            'entity_id' => $updated->id,
            'actor_id' => $request->user()?->id,
            'before' => $before,
            'after' => [
                'name' => $updated->name,
                'is_active' => $updated->is_active,
                'parent_id' => $updated->parent_id,
            ],
        ]);

        return $updated;
    }
}
