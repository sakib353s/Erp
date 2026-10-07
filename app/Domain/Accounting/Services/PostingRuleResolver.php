<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\PostingRule;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Resolves source-document event types to concrete Accounts via
 * posting_rules (§7.2). Controllers never contain account codes.
 */
class PostingRuleResolver
{
    public function __construct(protected TenantContext $context) {}

    /**
     * Resolve all roles for an event into ordered [side, account] pairs.
     *
     * @return array<int, array{role: string, side: string, account: Account}>
     */
    public function resolve(string $eventType, ?string $docType = null, ?Carbon $asAt = null): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $asAt ??= now();

        $rules = PostingRule::query()
            ->where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->active()
            ->when($docType !== null, fn ($q) => $q->where(function ($qq) use ($docType) {
                $qq->whereNull('doc_type')->orWhere('doc_type', $docType);
            }))
            ->where(function ($q) use ($asAt) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asAt);
            })
            ->where(function ($q) use ($asAt) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asAt);
            })
            ->orderBy('position')
            ->with('account')
            ->get();

        if ($rules->isEmpty()) {
            throw new RuntimeException("No active posting rule for event: {$eventType}");
        }

        return $rules->map(fn (PostingRule $rule) => [
            'role' => $rule->role,
            'side' => $rule->side,
            'account' => $rule->account,
        ])->all();
    }

    /**
     * Convenience: role → Account for a single expected role.
     */
    public function accountFor(string $eventType, string $role, ?string $docType = null): Account
    {
        foreach ($this->resolve($eventType, $docType) as $resolved) {
            if ($resolved['role'] === $role) {
                return $resolved['account'];
            }
        }

        throw new RuntimeException("Posting rule role [{$role}] not found for event [{$eventType}].");
    }
}
