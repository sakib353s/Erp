<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Security\Services\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * First-boot installer orchestration (spec §49):
 *   1. operator runs `php artisan erp:setup-token` → one-time token,
 *   2. operator opens /setup, enters token + company + admin account,
 *   3. token is verified (rate-limited) and CONSUMED,
 *   4. company + head office branch + admin user + defaults are created
 *      inside ONE transaction,
 *   5. the whole setup flow closes permanently (GuardSetup returns 403).
 *
 * There is no default password anywhere in the process: the admin picks
 * their password here and it must pass the real PasswordPolicy.
 */
class SetupService
{
    public function __construct(
        protected CompanyService $company,
        protected SetupToken $token,
        protected PasswordPolicy $passwords,
        protected AuditRecorder $audit,
    ) {}

    public function inProgress(): bool
    {
        return ! $this->company->exists();
    }

    /** Whether an outstanding setup token exists (drives the /setup hint UI). */
    public function tokenExists(): bool
    {
        return $this->token->exists();
    }

    /**
     * @param array{
     *     token: string, company: array<string, mixed>,
     *     admin: array{name: string, email: string, password: string, password_confirmation: string}
     * } $input
     */
    public function run(array $input, string $ip): User
    {
        if ($this->company->exists()) {
            abort(403, 'Setup has already been completed for this instance.');
        }

        if ($this->token->blocked($ip)) {
            abort(429, 'Too many setup attempts. Generate a new token and try again.');
        }

        $this->token->recordAttempt($ip);

        if (! $this->token->verify($input['token'])) {
            throw ValidationException::withMessages([
                'token' => 'The setup token is invalid or has expired. Generate a new one with: php artisan erp:setup-token',
            ]);
        }

        // Real password policy — same rules the app enforces forever after.
        $policyErrors = $this->passwords->validate($input['admin']['password'], null, $input['admin']['email']);

        if ($policyErrors !== []) {
            throw ValidationException::withMessages(['admin.password' => $policyErrors]);
        }

        return DB::transaction(function () use ($input) {
            /** @var User $admin */
            $admin = $this->company->createFirst(
                $input['company'],
                fn (Company $company, Branch $branch) => User::create([
                    'company_id' => $company->id,
                    'name' => $input['admin']['name'],
                    'email' => $input['admin']['email'],
                    'password' => $input['admin']['password'], // hashed by model cast
                    'status' => 'active',
                    'is_super_admin' => true,
                    'branch_scope' => 'all',
                    'default_branch_id' => $branch->id,
                    'password_changed_at' => now(),
                    'email_verified_at' => now(),
                ]),
            );

            // Single-use: consumed only after the transaction commits.
            DB::afterCommit(fn () => $this->token->consume());

            // Its own action, like the company record's: completing first-boot
            // setup is not a settings edit, and the audit trail answers "who
            // changed which setting" with that action.
            $this->audit->record([
                'action' => 'instance.setup_completed',
                'entity_type' => 'instance',
                'entity_id' => null,
                'actor_id' => $admin->id,
                'actor_type' => 'user',
                'after' => ['event' => 'first_boot_setup_completed', 'admin_email' => $admin->email],
            ]);

            return $admin;
        });
    }
}
