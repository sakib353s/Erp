<?php

namespace App\Domain\Customers\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Customers\Models\CustomerAddress;
use App\Domain\Customers\Models\CustomerContact;
use App\Domain\Customers\Models\CustomerCreditHistory;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Customer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer master rules (§05) — the only place a customer is created,
 * mutated, blacklisted or given a credit limit.
 *
 * Invariants enforced here:
 *   · code is unique per company and generated when not supplied (no gaps
 *     reused: the generator takes max(code)+1 and is guarded by the unique index);
 *   · phone/email uniqueness is per company (duplicate-phone protection, 05-02);
 *   · blacklisting REQUIRES a reason and is never silent;
 *   · a credit-limit change appends to customer_credit_history — the limit
 *     itself is not a mutable free-floating number (05-16 audit trail);
 *   · blacklisted customers are refused at document creation entry points
 *     (see assertOrderable()).
 */
class CustomerService
{
    public function __construct(
        protected TenantContext $tenant,
        protected AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId = null): Customer
    {
        $companyId = (int) ($data['company_id'] ?? $this->tenant->companyId());

        if ($companyId <= 0) {
            throw new RuntimeException('A customer cannot be created without a company context.');
        }

        $data['company_id'] = $companyId;
        $data['code'] = $this->resolveCode($data['code'] ?? null, $companyId);
        $data = $this->normalise($data);

        $this->assertContactUnique($data, $companyId);

        return DB::transaction(function () use ($data, $actorId) {
            $customer = Customer::create($data);

            $this->audit->record([
                'action' => 'customers.customer_created',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'type' => $customer->type,
                    'phone' => $customer->phone,
                ],
            ]);

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Customer $customer, array $data, ?int $actorId = null): Customer
    {
        $data['company_id'] = $customer->company_id;
        $data = $this->normalise($data);

        if (isset($data['code'])) {
            $data['code'] = $this->resolveCode($data['code'], $customer->company_id, $customer->id);
        }

        $this->assertContactUnique($data, $customer->company_id, $customer->id);

        $before = $customer->only(['name', 'type', 'phone', 'email', 'credit_limit', 'is_active']);

        return DB::transaction(function () use ($customer, $data, $before, $actorId) {
            $customer->fill($data)->save();

            $this->audit->record([
                'action' => 'customers.customer_updated',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'actor_id' => $actorId,
                'before' => $before,
                'after' => $customer->only(['name', 'type', 'phone', 'email', 'credit_limit', 'is_active']),
            ]);

            return $customer;
        });
    }

    /**
     * Credit-limit decision (05-16): the change and its reason are recorded
     * together, always.
     *
     * @param array{limit?: float|int|string, credit_days?: int|string, reason?: string|null} $data
     */
    public function setCreditLimit(Customer $customer, array $data, ?int $actorId = null): CustomerCreditHistory
    {
        $newLimit = round((float) ($data['limit'] ?? 0), 2);
        $newDays = max(0, (int) ($data['credit_days'] ?? $customer->credit_days));

        if ($newLimit < 0 || $newDays > 365) {
            throw new RuntimeException('Credit limit must be positive and credit days cannot exceed 365.');
        }

        return DB::transaction(function () use ($customer, $newLimit, $newDays, $data, $actorId) {
            $history = CustomerCreditHistory::create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'old_limit' => $customer->credit_limit,
                'new_limit' => $newLimit,
                'old_credit_days' => $customer->credit_days,
                'new_credit_days' => $newDays,
                'reason' => $data['reason'] ?? null,
                'changed_by' => $actorId,
            ]);

            $customer->forceFill([
                'credit_limit' => $newLimit,
                'credit_days' => $newDays,
            ])->save();

            $this->audit->record([
                'action' => 'customers.credit_limit_changed',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'actor_id' => $actorId,
                'before' => ['credit_limit' => $history->old_limit, 'credit_days' => $history->old_credit_days],
                'after' => ['credit_limit' => $history->new_limit, 'credit_days' => $history->new_credit_days],
                'reason' => $history->reason,
            ]);

            return $history;
        });
    }

    /** 05-21 — reason is mandatory; un-blacklisting is explicit too. */
    public function setBlacklist(Customer $customer, bool $blacklisted, ?string $reason, ?int $actorId = null): Customer
    {
        if ($blacklisted && trim((string) $reason) === '') {
            throw new RuntimeException('A blacklist entry requires a reason.');
        }

        return DB::transaction(function () use ($customer, $blacklisted, $reason, $actorId) {
            $customer->forceFill([
                'is_blacklisted' => $blacklisted,
                'blacklist_reason' => $blacklisted ? $reason : null,
                'blacklisted_by' => $blacklisted ? $actorId : null,
                'blacklisted_at' => $blacklisted ? now() : null,
            ])->save();

            $this->audit->record([
                'action' => $blacklisted ? 'customers.customer_blacklisted' : 'customers.customer_restored',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);

            return $customer;
        });
    }

    /** Gate used by order / invoice entry points before a document is built. */
    public function assertOrderable(Customer $customer): void
    {
        if ($customer->is_blacklisted) {
            throw new RuntimeException(
                "Customer {$customer->name} is blacklisted: ".($customer->blacklist_reason ?: 'no reason recorded')
            );
        }

        if (! $customer->is_active) {
            throw new RuntimeException("Customer {$customer->name} is inactive.");
        }
    }

    /**
     * Would this new exposure breach the limit? Returns the projection instead
     * of throwing, because the caller decides between block and warn (config).
     *
     * @return array{limit:float, exposure:float, projected:float, breach:bool, over_by:float}
     */
    public function creditProjection(Customer $customer, float $newExposure): array
    {
        $limit = (float) $customer->credit_limit;
        $exposure = $this->outstanding($customer);
        $projected = $exposure + $newExposure;

        return [
            'limit' => $limit,
            'exposure' => $exposure,
            'projected' => $projected,
            'breach' => $limit > 0 && $projected > $limit,
            'over_by' => $limit > 0 ? max(0, $projected - $limit) : 0.0,
        ];
    }

    /** Outstanding receivable for one customer, derived — never a stored column. */
    public function outstanding(Customer $customer): float
    {
        $invoiced = (float) DB::table('invoices')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->sum('grand_total');

        $paid = (float) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.company_id', $customer->company_id)
            ->where('payments.customer_id', $customer->id)
            ->where('payments.status', 'posted')
            ->where('payment_allocations.allocatable_type', 'like', '%Invoice')
            ->sum('payment_allocations.amount');

        $opening = ($customer->opening_balance_type ?? 'due') === 'due'
            ? (float) $customer->opening_balance
            : -(float) $customer->opening_balance;

        return round($invoiced - $paid + $opening, 4);
    }

    /**
     * Ensure exactly one default address for the given purpose (05-06).
     */
    public function makeDefaultAddress(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address) {
            CustomerAddress::query()
                ->where('customer_id', $address->customer_id)
                ->where('label', $address->label)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);

            $address->forceFill(['is_default' => true])->save();
        });
    }

    /** Contacts: one primary flag per customer (05-02). */
    public function makePrimaryContact(CustomerContact $contact): void
    {
        DB::transaction(function () use ($contact) {
            CustomerContact::query()
                ->where('customer_id', $contact->customer_id)
                ->where('id', '!=', $contact->id)
                ->update(['is_primary' => false]);

            $contact->forceFill(['is_primary' => true])->save();
        });
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $data */
    protected function normalise(array $data): array
    {
        foreach (['phone', 'alt_phone', 'email', 'bin', 'tax_vat_no', 'code'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }

        if (! empty($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit'] = round((float) $data['credit_limit'], 2);
        }

        if (array_key_exists('credit_days', $data)) {
            $data['credit_days'] = max(0, (int) $data['credit_days']);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function assertContactUnique(array $data, int $companyId, ?int $ignoreId = null): void
    {
        foreach (['phone', 'email'] as $field) {
            if (empty($data[$field])) {
                continue;
            }

            $exists = Customer::query()
                ->where('company_id', $companyId)
                ->where($field, $data[$field])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                throw new RuntimeException(
                    "Another customer already uses this {$field} ({$data[$field]}). Search before adding a duplicate party."
                );
            }
        }
    }

    protected function resolveCode(?string $code, int $companyId, ?int $ignoreId = null): string
    {
        if ($code !== null && $code !== '') {
            $taken = Customer::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($taken) {
                throw new RuntimeException("Customer code {$code} is already in use.");
            }

            return $code;
        }

        $last = Customer::query()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'CUST-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last !== null ? ((int) substr($last, 5)) + 1 : 1;

        return 'CUST-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
