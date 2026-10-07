<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Http\Request;

/**
 * CreateAccount (09-04). Permission is enforced by route middleware;
 * this action owns the domain rules (code uniqueness, parent must be
 * group, type match) and writes an audit diff.
 */
class CreateAccount
{
    public function __construct(
        protected AccountService $accounts,
        protected AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(array $data, Request $request): Account
    {
        $account = $this->accounts->create($data);

        $this->audit->record([
            'action' => 'accounting.account_created',
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'actor_id' => $request->user()?->id,
            'after' => [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'is_group' => $account->is_group,
            ],
        ]);

        return $account;
    }
}
