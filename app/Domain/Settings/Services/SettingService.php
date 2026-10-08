<?php

namespace App\Domain\Settings\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Settings\Setting;
use App\Domain\Settings\SettingHistory;
use App\Domain\Foundation\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        protected InvariantGuard $guard,
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

    /**
     * The value actually in force, resolving the branch override first.
     *
     * `get()` answers from one scope — the row at that scope, else the declared
     * default. That is right for editing a group and wrong for reading it: for a
     * branch to be able to decide something for itself, the code that *uses* the
     * value has to ask this question, or the branch's own number is a row nobody
     * reads. Order: this branch's row, then the company's, then the default.
     */
    public function effective(string $group, string $key, ?int $branchId = null): mixed
    {
        $branchId ??= $this->context->branchId();

        if ($branchId !== null && $branchId !== Setting::COMPANY_SCOPE) {
            $row = $this->row($group, $key, $branchId);

            if ($row !== null) {
                return $this->decode($row);
            }
        }

        return $this->get($group, $key);
    }

    /** The effective value read as a switch, with the company value as fallback. */
    public function effectiveBool(string $group, string $key, ?bool $fallback = null, ?int $branchId = null): bool
    {
        $value = $this->effective($group, $key, $branchId);

        if ($value === null) {
            return $fallback ?? (bool) config("erp.settings.groups.{$group}.fields.{$key}.default", false);
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /** The effective value read as a whole number. */
    public function effectiveInt(string $group, string $key, ?int $fallback = null, ?int $branchId = null): int
    {
        $value = $this->effective($group, $key, $branchId);

        return $value === null
            ? ($fallback ?? (int) config("erp.settings.groups.{$group}.fields.{$key}.default", 0))
            : (int) $value;
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

    /** Does this branch hold its own value for this key? */
    public function hasOverride(string $group, string $key, int $branchId): bool
    {
        if ($branchId === Setting::COMPANY_SCOPE) {
            return false;
        }

        return $this->ownRow($group, $key, $branchId) !== null;
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

        /*
         * §15-35: the invariant floor. This is the one function every write goes
         * through — screen, service or command — so the rules that keep the
         * system trustworthy are enforced here rather than in a form. A refusal
         * names the field and says why, and is audited as an invariant denial so
         * an attempt is visible even when nothing changed.
         */
        try {
            $this->guard->assertWrite($group, $key, $value, $branchId);
        } catch (ValidationException $refused) {
            $this->audit->record([
                'action' => 'config.invariant_denied',
                'entity_type' => 'setting',
                'entity_id' => null,
                'result' => 'denied',
                'reason' => $refused->validator->errors()->first(),
                'after' => ['group' => $group, 'key' => $key, 'branch_id' => $branchId, 'value' => is_scalar($value) ? $value : null],
                'actor_id' => $actor?->id,
            ]);

            throw $refused;
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

    /**
     * Persist a whole form group atomically.
     *
     * Returns what happened, in two lists, because a screen that says “Saved 4.”
     * after refusing three of them would be lying about the only thing it knows:
     * `saved` are the keys that are now stored, `rejected` are the protected and
     * invariant ones that were not — the caller tells the person which is which
     * instead of counting refusals as successes. An invariant refusal throws
     * (the field belongs to a form the person is looking at); a protected key is
     * refused quietly here and named in the result.
     *
     * @return array{saved: array<int, string>, rejected: array<int, string>}
     */
    public function setGroup(string $group, array $values, ?int $branchId = null, ?User $actor = null): array
    {
        $saved = [];
        $rejected = [];

        DB::transaction(function () use ($group, $values, $branchId, $actor, &$saved, &$rejected) {
            foreach ($values as $key => $value) {
                if ($this->set($group, (string) $key, $value, $branchId, $actor)) {
                    $saved[] = (string) $key;
                } else {
                    $rejected[] = (string) $key;
                }
            }
        });

        return ['saved' => $saved, 'rejected' => $rejected];
    }

    /**
     * Remove a branch override, so the branch falls back to what the company
     * says. Deleting the row is the only way to say “this branch has no opinion”
     * — writing the company's current value instead would freeze it at today's
     * number and quietly stop following the company tomorrow.
     */
    public function forget(string $group, string $key, int $branchId, ?User $actor = null): bool
    {
        $companyId = $this->context->companyId();
        abort_if($companyId === null, 500, 'Company context missing.');

        if ($branchId === Setting::COMPANY_SCOPE) {
            return false;
        }

        $this->context->assertBranchAccess($branchId);

        // The branch's own row, not the value in force: a branch that never
        // overrode anything must not have its delete take the company's row
        // with it.
        $row = $this->ownRow($group, $key, $branchId);

        if ($row === null) {
            return false;
        }

        DB::transaction(function () use ($row, $group, $key, $branchId, $companyId, $actor) {
            SettingHistory::create([
                'setting_id' => $row->id,
                'old_value' => $row->value,
                'new_value' => null,
                'changed_by' => $actor?->id,
                'correlation_id' => $this->correlationId(),
                'changed_at' => now(),
            ]);

            $this->audit->record([
                'action' => 'config.update',
                'entity_type' => 'setting',
                'entity_id' => $row->id,
                'before' => ['group' => $group, 'key' => $key, 'branch_id' => $branchId, 'value' => $this->auditValue($row->value_type, $row->value)],
                'after' => ['group' => $group, 'key' => $key, 'branch_id' => $branchId, 'value' => null, 'removed_override' => true],
                'actor_id' => $actor?->id,
            ]);

            $row->delete();
        });

        return true;
    }

    /**
     * Every branch that holds an override of this group — the list a company
     * admin needs in order to know where a figure they did not set came from.
     *
     * @return array<int, array{branch_id: int, keys: array<int, string>, updated_at: mixed, updated_by: ?int}>
     */
    public function overrides(string $group): array
    {
        $companyId = $this->context->companyId();

        if ($companyId === null) {
            return [];
        }

        return Setting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', '!=', Setting::COMPANY_SCOPE)
            ->where('setting_group', $group)
            ->orderBy('branch_id')
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($rows, $branchId) => [
                'branch_id' => (int) $branchId,
                'keys' => $rows->pluck('setting_key')->all(),
                'updated_at' => $rows->max('updated_at'),
                'updated_by' => $rows->max('updated_by'),
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The row that answers for a scope: the branch's own value when a branch is
     * asked for (falling back to the company's), and *only* the company's when
     * no branch is named.
     *
     * `null` used to mean "whatever branch this request is on", which made the
     * company value unreadable the moment anybody worked in a branch: the
     * company's own row could not be read to compare against, a branch form
     * showed the branch's number in the "company value" column, and forgetting
     * an override — which reads before it deletes — would delete the company's
     * number instead. `effective()` is the reader that resolves branch-first;
     * this one answers the scope it was asked about.
     */
    protected function row(string $group, string $key, ?int $branchId): ?Setting
    {
        $companyId = $this->context->companyId();

        if ($companyId === null) {
            return null;
        }

        $branch = $branchId ?? Setting::COMPANY_SCOPE;

        return Setting::query()
            ->where('company_id', $companyId)
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->whereIn('branch_id', array_values(array_unique([$branch, Setting::COMPANY_SCOPE])))
            ->orderByRaw('branch_id = 0 ASC') // branch-specific row wins
            ->first();
    }

    /** This branch's own row for the key — no fallback to the company's. */
    protected function ownRow(string $group, string $key, int $branchId): ?Setting
    {
        $companyId = $this->context->companyId();

        if ($companyId === null || $branchId === Setting::COMPANY_SCOPE) {
            return null;
        }

        return Setting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('setting_group', $group)
            ->where('setting_key', $key)
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
