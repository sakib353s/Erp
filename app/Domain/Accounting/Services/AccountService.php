<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountGroup;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Chart-of-accounts mutations (09-03…09-05). System accounts cannot be
 * deleted; activity history blocks reparenting that would break
 * statement mapping silently (09-04).
 */
class AccountService
{
    public function __construct(protected TenantContext $context) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Account
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (Account::query()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            throw new RuntimeException('Account code already exists.');
        }

        if (! empty($data['parent_id'])) {
            $parent = Account::query()
                ->where('company_id', $companyId)
                ->whereKey($data['parent_id'])
                ->firstOrFail();

            if (! $parent->is_group) {
                throw new RuntimeException('Parent must be a group account.');
            }

            if ($parent->type !== $data['type']) {
                throw new RuntimeException('Child account type must match parent type.');
            }
        }

        return DB::transaction(function () use ($data, $companyId) {
            return Account::create([
                'company_id' => $companyId,
                'account_group_id' => $data['account_group_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'sub_type' => $data['sub_type'] ?? null,
                'is_group' => (bool) ($data['is_group'] ?? false),
                'is_system' => false,
                'is_active' => $data['is_active'] ?? true,
                'is_control_account' => (bool) ($data['is_control_account'] ?? false),
                'is_cash' => (bool) ($data['is_cash'] ?? false),
                'is_bank' => (bool) ($data['is_bank'] ?? false),
                'currency' => $data['currency'] ?? 'BDT',
                'description' => $data['description'] ?? null,
                'sort' => $data['sort'] ?? 0,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        if ($account->is_system && isset($data['parent_id']) && $data['parent_id'] !== $account->parent_id) {
            throw new RuntimeException('System accounts cannot be reparented.');
        }

        $hasHistory = $account->journalLines()->exists();

        if ($hasHistory) {
            // History-breaking changes blocked: type/code cannot silently change
            foreach (['type', 'code'] as $locked) {
                if (array_key_exists($locked, $data) && $data[$locked] !== $account->{$locked}) {
                    throw new RuntimeException(sprintf(
                        'Account %s has posting history and cannot change its %s.',
                        $account->code,
                        $locked,
                    ));
                }
            }

            if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] !== (int) $account->parent_id) {
                throw new RuntimeException('Account with posting history cannot be reparented.');
            }
        }

        if (! empty($data['parent_id'])) {
            $parent = Account::query()
                ->where('company_id', $account->company_id)
                ->whereKey($data['parent_id'])
                ->firstOrFail();

            if ($parent->id === $account->id) {
                throw new RuntimeException('An account cannot be its own parent.');
            }

            if (! $parent->is_group) {
                throw new RuntimeException('Parent must be a group account.');
            }
        }

        return DB::transaction(function () use ($account, $data) {
            $account->fill(collect($data)->only([
                'account_group_id', 'parent_id', 'name', 'sub_type',
                'is_active', 'is_control_account', 'is_cash', 'is_bank',
                'currency', 'description', 'sort',
            ])->all());
            $account->save();

            return $account;
        });
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new RuntimeException('System accounts cannot be deleted.');
        }

        if ($account->journalLines()->exists()) {
            throw new RuntimeException('Accounts with posting history cannot be deleted.');
        }

        if ($account->children()->exists()) {
            throw new RuntimeException('Delete or move child accounts first.');
        }

        $account->delete();
    }

    /**
     * Hierarchical COA tree for display (09-03).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get()
            ->groupBy('parent_id');

        $build = function (?int $parentId) use (&$build, $accounts): array {
            return $accounts->get($parentId, collect())->map(function (Account $account) use ($build) {
                return [
                    'account' => $account,
                    'children' => $build($account->id),
                ];
            })->values()->all();
        };

        return $build(null);
    }

    /** Structural account groups (statement mapping). */
    public function ensureDefaultGroups(): void
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $groups = [
            ['code' => 'ASSET', 'name' => 'Assets', 'type' => 'asset', 'sort' => 10],
            ['code' => 'LIABILITY', 'name' => 'Liabilities', 'type' => 'liability', 'sort' => 20],
            ['code' => 'EQUITY', 'name' => 'Equity', 'type' => 'equity', 'sort' => 30],
            ['code' => 'REVENUE', 'name' => 'Revenue', 'type' => 'revenue', 'sort' => 40],
            ['code' => 'EXPENSE', 'name' => 'Expenses', 'type' => 'expense', 'sort' => 50],
        ];

        foreach ($groups as $group) {
            AccountGroup::updateOrCreate(
                ['company_id' => $companyId, 'code' => $group['code']],
                [
                    'name' => $group['name'],
                    'type' => $group['type'],
                    'sort' => $group['sort'],
                    'is_system' => true,
                ],
            );
        }
    }
}
