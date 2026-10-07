<?php

namespace App\Domain\Settings\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Settings\Setting;
use App\Domain\Settings\SettingHistory;
use App\Domain\Foundation\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Runtime configuration store (correction G: thresholds, policies and
 * number formats live HERE — in the database — not in controllers).
 *
 * - values resolve: branch row → company row → config/erp.php default;
 * - secret values are encrypted at rest (plaintext never leaves PHP);
 * - protected/invariant keys are refused and the attempt is audited;
 * - every change writes setting_history + a tamper-evident audit event.
 */
class SettingService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Reads                                                               */
    /* ------------------------------------------------------------------ */

    public function get(string $group, string $key, mixed $fallback = null, ?int $branchId = null): mixed
    {
        $row = $this->row($group, $key, $branchId);

        if ($row !== null) {
            return $this->decode($row);
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return config("erp.settings.groups.{$group}.fields.{$key}.default");
    }

    public function getBool(string $group, string $key, ?bool $fallback = null): bool
    {
        return (bool) $this->get($group, $key, $fallback);
    }

    public function getInt(string $group, string $key, ?int $fallback = null): int
    {
        return (int) $this->get($group, $key, $fallback);
    }

    /** All values for a settings form (defaults merged with stored rows). */
    public function group(string $group, ?int $branchId = null): array
    {
        $fields = config("erp.settings.groups.{$group}.fields", []);
        $out = [];

        foreach ($fields as $key => $meta) {
            $out[$key] = [
                'label' => $meta['label'],
                'type' => $meta['type'] ?? 'string',
                'options' => $meta['options'] ?? null,
                'help' => $meta['help'] ?? null,
                'min' => $meta['min'] ?? null,
                'max' => $meta['max'] ?? null,
                'value' => $this->get($group, $key, null, $branchId),
                'default' => $meta['default'] ?? null,
                'override' => $this->row($group, $key, $branchId) !== null,
            ];
        }

        return $out;
    }

    public function hasExplicitGroup(string $group, ?int $branchId = null): bool
    {
        $companyId = $this->context->companyId();
        if ($companyId === null) {
            return false;
        }

        return Setting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId ?? Setting::COMPANY_SCOPE)
            ->where('setting_group', $group)
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /* Writes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Persist one key. Returns true when stored, false when the key is a
     * protected invariant (attempt audited as config.protected_denied).
     */
    public function set(string $group, string $key, mixed $value, ?int $branchId = null, ?User $actor = null): bool
    {
        $companyId = $this->context->companyId();
        abort_if($companyId === null, 500, 'Company context missing.');

        $fieldMeta = config("erp.settings.groups.{$group}.fields.{$key}");

        abort_if($fieldMeta === null, 422, "Unknown setting {$group}.{$key}.");

        if ($this->isProtected($key)) {
            $this->audit->record([
                'action' => 'config.protected_denied',
                'entity_type' => 'setting',
                'entity_id' => null,
                'result' => 'denied',
                'reason' => "protected key {$group}.{$key}",
                'after' => ['group' => $group, 'key' => $key],
                'actor_id' => $actor?->id,
            ]);

            return false;
        }

        if ($branchId !== null && $branchId !== 0) {
            $this->context->assertBranchAccess($branchId);
        }

        $type = $fieldMeta['type'] ?? 'string';
        $encoded = $this->encode($type, $value);

        DB::transaction(function () use ($group, $key, $encoded, $type, $companyId, $branchId, $actor) {
            $row = Setting::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId ?? Setting::COMPANY_SCOPE)
                ->where('setting_group', $group)
                ->where('setting_key', $key)
                ->lockForUpdate()
                ->first();

            $old = $row?->value;

            $row ??= new Setting([
                'company_id' => $companyId,
                'branch_id' => $branchId ?? Setting::COMPANY_SCOPE,
                'setting_group' => $group,
                'setting_key' => $key,
            ]);

            $row->fill([
                'value' => $encoded['value'],
                'value_type' => $encoded['type'],
                'is_encrypted' => $encoded['encrypted'],
                'updated_by' => $actor?->id,
            ])->save();

            SettingHistory::create([
                'setting_id' => $row->id,
                'old_value' => $old,
                'new_value' => $encoded['value'],
                'changed_by' => $actor?->id,
                'correlation_id' => $this->correlationId(),
                'changed_at' => now(),
            ]);

            $this->audit->record([
                'action' => 'config.update',
                'entity_type' => 'setting',
                'entity_id' => $row->id,
                'before' => ['group' => $group, 'key' => $key, 'value' => $this->auditValue($type, $old)],
                'after' => ['group' => $group, 'key' => $key, 'value' => $this->auditValue($type, $encoded['value'], $encoded['encrypted'])],
                'actor_id' => $actor?->id,
            ]);
        });

        return true;
    }

    /** Persist a whole form group atomically; returns list of rejected keys. */
    public function setGroup(string $group, array $values, ?int $branchId = null, ?User $actor = null): array
    {
        $rejected = [];

        DB::transaction(function () use ($group, $values, $branchId, $actor, &$rejected) {
            foreach ($values as $key => $value) {
                if (! $this->set($group, (string) $key, $value, $branchId, $actor)) {
                    $rejected[] = (string) $key;
                }
            }
        });

        return $rejected;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    protected function row(string $group, string $key, ?int $branchId): ?Setting
    {
        $companyId = $this->context->companyId();

        if ($companyId === null) {
            return null;
        }

        $branch = $branchId ?? $this->context->branchId() ?? Setting::COMPANY_SCOPE;

        return Setting::query()
            ->where('company_id', $companyId)
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->whereIn('branch_id', array_values(array_unique([$branch, Setting::COMPANY_SCOPE])))
            ->orderByRaw('branch_id = 0 ASC') // branch-specific row wins
            ->first();
    }

    protected function decode(Setting $row): mixed
    {
        $raw = $row->is_encrypted ? Crypt::decryptString((string) $row->value) : $row->value;

        return match ($row->value_type) {
            'int' => (int) $raw,
            'bool' => $raw === '1' || $raw === 'true' || $raw === 1,
            'decimal' => (float) $raw,
            'json' => json_decode((string) $raw, true),
            default => $raw,
        };
    }

    /** @return array{value:?string,type:string,encrypted:bool} */
    protected function encode(string $type, mixed $value): array
    {
        $encrypted = false;

        $raw = match ($type) {
            'boolean' => $value ? '1' : '0',
            'number' => (string) (int) $value,
            'decimal' => (string) (float) $value,
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => $value === null ? null : (string) $value,
        };

        $valueType = match ($type) {
            'boolean' => 'bool',
            'number' => 'int',
            'decimal' => 'decimal',
            'json' => 'json',
            default => 'string',
        };

        // Secret form fields (type=secret) are encrypted at rest.
        if ($type === 'secret') {
            $encrypted = true;
            $valueType = 'encrypted';
            $raw = $raw === null ? null : Crypt::encryptString($raw);
        }

        return ['value' => $raw, 'type' => $valueType, 'encrypted' => $encrypted];
    }

    protected function isProtected(string $key): bool
    {
        return in_array($key, config('erp.settings.protected_keys', []), true);
    }

    protected function auditValue(string $type, mixed $value, bool $encrypted = false): mixed
    {
        if ($encrypted || $type === 'secret') {
            return '[encrypted]';
        }

        return $value;
    }

    protected function correlationId(): ?string
    {
        return request()->attributes->get('correlation_id');
    }
}
