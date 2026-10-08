<?php

namespace App\Domain\Settings\Services;

use Illuminate\Validation\ValidationException;

/**
 * §15-35 — the settings that a settings screen may not be allowed to change.
 *
 * A configurable system has a floor, and the floor is not a UI convention: it is
 * the set of values whose removal would take away the machinery that keeps the
 * rest trustworthy. Letting a form turn off password floors, shorten the audit
 * trail below what a dispute needs, or write a company-wide policy onto one
 * branch would mean the system's own guarantees were configuration — and a
 * guarantee anybody can switch off is not a guarantee.
 *
 * So this guard is called from {@see SettingService::set()}, which is the one
 * function every write goes through, screen or service or command. Three
 * refusals, each with its reason in words:
 *
 *  · a **floor** — a numeric setting may not be written below the minimum the
 *    system must keep, whatever the form says. The form's own `min` is a hint
 *    for people; this is the rule.
 *  · a **protected key** — instance identity and provisioning keys, which no
 *    screen may rewrite (the service also refuses them; the guard is where the
 *    list is read from one place).
 *  · a **scope** — a policy group may not be overridden per branch. Display and
 *    document settings differ between outlets; who may log in, how long the
 *    audit trail is kept and what has to be approved do not.
 *
 * The refusal is a `ValidationException`, so a screen shows it on the field the
 * person just touched, and the service audits it as an invariant denial.
 */
class InvariantGuard
{
    /**
     * Numeric floors, per group and key — the lowest value the system will keep.
     * Each one exists because a lower number would take something away rather
     * than tune it.
     *
     * @var array<string, array<string, array{floor: int|float, why: string}>>
     */
    public const FLOORS = [
        'security' => [
            'password_min_length' => ['floor' => 8, 'why' => 'A shorter minimum than 8 characters is not a password policy.'],
            'lockout_max_attempts' => ['floor' => 3, 'why' => 'Locking out after fewer than 3 attempts would lock people out of their own work.'],
            'lockout_minutes' => ['floor' => 1, 'why' => 'A lockout that lasts no time is not a lockout.'],
            'session_idle_minutes' => ['floor' => 5, 'why' => 'An idle timeout under 5 minutes logs people out mid-task instead of protecting anything.'],
        ],
        'audit' => [
            'retention_days' => ['floor' => 30, 'why' => 'The audit trail is evidence: it may not be kept for less than 30 days.'],
            'chain_verify_reminder_hours' => ['floor' => 1, 'why' => 'A reminder that never comes is not a reminder.'],
        ],
        'workflow' => [
            'default_step_due_hours' => ['floor' => 1, 'why' => 'A step that is due in no time cannot be answered.'],
        ],
    ];

    /**
     * Groups that are company policy and therefore may not be overridden for one
     * branch: who may log in, how long evidence is kept, what must be approved.
     *
     * @var array<int, string>
     */
    public const COMPANY_ONLY_GROUPS = ['security', 'audit', 'workflow', 'notifications'];

    /**
     * Check one write. Returns normally when the value is allowed and throws a
     * field-scoped ValidationException when it is not.
     *
     * @throws ValidationException
     */
    public function assertWrite(string $group, string $key, mixed $value, ?int $branchId = null): void
    {
        $this->assertNotProtected($key);
        $this->assertBranchScope($group, $branchId);
        $this->assertFloor($group, $key, $value);
    }

    /** True when the key is one of the instance's protected identities. */
    public function isProtectedKey(string $key): bool
    {
        return in_array($key, (array) config('erp.settings.protected_keys', []), true);
    }

    /** True when this group may legitimately differ from branch to branch. */
    public function isCompanyOnly(string $group): bool
    {
        return in_array($group, self::COMPANY_ONLY_GROUPS, true);
    }

    /**
     * The floors as a table, for the screens and for the docs: a settings page
     * can then say why a number will not go lower instead of just refusing.
     *
     * @return array<string, array<string, array{floor: int|float, why: string}>>
     */
    public function floors(): array
    {
        return self::FLOORS;
    }

    /** @throws ValidationException */
    protected function assertNotProtected(string $key): void
    {
        if ($this->isProtectedKey($key)) {
            throw ValidationException::withMessages([
                $key => sprintf('“%s” is part of this instance’s identity and cannot be changed from a settings screen.', $key),
            ]);
        }
    }

    /** @throws ValidationException */
    protected function assertBranchScope(string $group, ?int $branchId): void
    {
        // A company-scope write ($branchId 0 or null) is always allowed: that is
        // the policy itself, not an override of it.
        if ($branchId === null || $branchId === 0) {
            return;
        }

        if ($this->isCompanyOnly($group)) {
            throw ValidationException::withMessages([
                'branch_id' => sprintf(
                    'The %s group is company policy: it applies to every branch and cannot be overridden for one.',
                    $group,
                ),
            ]);
        }
    }

    /** @throws ValidationException */
    protected function assertFloor(string $group, string $key, mixed $value): void
    {
        $rule = self::FLOORS[$group][$key] ?? null;

        if ($rule === null || $value === null || $value === '') {
            return;
        }

        // Booleans are not numbers here: `true` must not read as 1 and pass a
        // floor test by accident.
        if (! is_numeric($value)) {
            return;
        }

        if ((float) $value < (float) $rule['floor']) {
            throw ValidationException::withMessages([
                'settings.'.$key => sprintf(
                    '%s The lowest this system will keep is %s.',
                    $rule['why'],
                    (string) $rule['floor'],
                ),
            ]);
        }
    }
}
