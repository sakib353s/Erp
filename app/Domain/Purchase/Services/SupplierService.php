<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Supplier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Supplier party rules (§06): code generation, duplicate detection (a supplier
 * entered twice is how bills get paid twice) and the blacklist that always
 * carries a reason and an actor.
 */
class SupplierService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId = null): Supplier
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for suppliers.');

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A supplier needs a name.');
        }

        $this->assertNoDuplicate($name, $data['phone'] ?? null, $data['bin'] ?? null, null);

        return DB::transaction(function () use ($data, $name, $companyId, $actorId) {
            $supplier = Supplier::create([
                ...$data,
                'company_id' => $companyId,
                'name' => $name,
                'code' => $data['code'] ?? $this->nextCode($companyId),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => $actorId,
            ]);

            $this->audit->record([
                'action' => 'purchase.supplier_created',
                'entity_type' => 'supplier',
                'entity_id' => $supplier->id,
                'actor_id' => $actorId,
                'after' => ['code' => $supplier->code, 'name' => $supplier->name],
            ]);

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Supplier $supplier, array $data, ?int $actorId = null): Supplier
    {
        $name = trim((string) ($data['name'] ?? $supplier->name));

        $this->assertNoDuplicate($name, $data['phone'] ?? $supplier->phone, $data['bin'] ?? $supplier->bin, $supplier->id);

        $before = $supplier->only(['name', 'phone', 'email', 'bin', 'payment_terms_days', 'credit_limit', 'is_active']);

        $supplier->fill([...$data, 'name' => $name])->save();

        $this->audit->record([
            'action' => 'purchase.supplier_updated',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'actor_id' => $actorId,
            'before' => $before,
            'after' => $supplier->only(['name', 'phone', 'email', 'bin', 'payment_terms_days', 'credit_limit', 'is_active']),
        ]);

        return $supplier;
    }

    /** Blacklisting always states why — and who decided it. */
    public function blacklist(Supplier $supplier, string $reason, ?int $actorId = null): Supplier
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Blacklisting a supplier requires a reason.');
        }

        $openOrders = $supplier->orders()->open()->count();

        if ($openOrders > 0) {
            throw new RuntimeException(sprintf(
                '%d open purchase order(s) still reference this supplier. Close or cancel them first.',
                $openOrders,
            ));
        }

        $supplier->forceFill([
            'is_blacklisted' => true,
            'blacklist_reason' => $reason,
            'blacklisted_at' => now(),
            'blacklisted_by' => $actorId,
        ])->save();

        $this->audit->record([
            'action' => 'purchase.supplier_blacklisted',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'actor_id' => $actorId,
            'reason' => $reason,
            'after' => ['name' => $supplier->name],
        ]);

        return $supplier;
    }

    public function unblacklist(Supplier $supplier, ?int $actorId = null): Supplier
    {
        $before = $supplier->blacklist_reason;

        $supplier->forceFill([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
            'blacklisted_at' => null,
            'blacklisted_by' => null,
        ])->save();

        $this->audit->record([
            'action' => 'purchase.supplier_reinstated',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'actor_id' => $actorId,
            'before' => ['reason' => $before],
            'after' => ['name' => $supplier->name, 'is_blacklisted' => false],
        ]);

        return $supplier;
    }

    /** Refuse a second record for the same phone, BIN or (case-insensitive) name. */
    protected function assertNoDuplicate(string $name, ?string $phone, ?string $bin, ?int $ignoreId): void
    {
        $query = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw new RuntimeException("A supplier named \"{$name}\" already exists.");
        }

        if ($bin !== null && trim($bin) !== '') {
            $dupe = Supplier::query()->where('bin', $bin)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

            if ($dupe) {
                throw new RuntimeException("Another supplier already uses BIN {$bin}.");
            }
        }

        if ($phone !== null && trim($phone) !== '') {
            $dupe = Supplier::query()->where('phone', $phone)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

            if ($dupe) {
                throw new RuntimeException("Another supplier already uses phone {$phone}.");
            }
        }
    }

    /** SUP-00001 per company. */
    public function nextCode(int $companyId): string
    {
        $last = Supplier::query()->where('company_id', $companyId)->orderByDesc('id')->value('code');
        $next = $last !== null && preg_match('/SUP-(\d+)$/', (string) $last, $m) === 1 ? ((int) $m[1]) + 1 : 1;

        return sprintf('SUP-%05d', $next);
    }
}
