<?php

namespace Tests\Concerns;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Platform\Services\SetupService;
use App\Domain\Platform\Services\SetupToken;
use Database\Seeders\PortalSeeder;

/**
 * Boots a REAL ERP instance through the actual first-boot flow: one-time
 * token verification, the real PasswordPolicy, the singleton-company
 * guard, head-office branch, Administrator role and structural defaults.
 * Nothing is faked or short-circuited.
 */
trait CreatesERPInstance
{
    protected const ADMIN_EMAIL = 'owner@instance.test';

    protected const ADMIN_PASSWORD = 'Hn7#kPm2$wQ9xL';

    protected static int $userSeq = 0;

    protected static int $roleSeq = 0;

    /** Run the genuine first-boot installer; returns the super admin. */
    protected function bootInstance(): User
    {
        $tokens = app(SetupToken::class);
        $tokens->consume(); // deterministic: never inherit a token from another test

        $token = $tokens->generate();

        $admin = app(SetupService::class)->run([
            'token' => $token,
            'company' => ['name' => 'Nile Fashions Ltd'],
            'admin' => [
                'name' => 'Instance Owner',
                'email' => self::ADMIN_EMAIL,
                'password' => self::ADMIN_PASSWORD,
                'password_confirmation' => self::ADMIN_PASSWORD,
            ],
        ], '127.0.0.1');

        $tokens->consume(); // the token is single-use; never leak the file between tests

        $this->seed(PortalSeeder::class);

        $this->bindTenantContext($admin);

        return $admin;
    }

    /**
     * Mirror the trusted per-request context SetTenantContext builds on
     * the web stack. Omit the branch to leave branch-scoped queries
     * unfiltered (console style); pass one to exercise the scope filter.
     */
    protected function bindTenantContext(?User $user = null, ?Branch $branch = null): TenantContext
    {
        $context = app(TenantContext::class);
        $context->setCompany(Company::current());
        $context->setUser($user);
        $context->setBranch($branch);

        return $context;
    }

    protected function defaultBranch(): Branch
    {
        return Branch::query()->where('is_default', true)->firstOrFail();
    }

    /** An active user inside the instance (all-branch scope unless given). */
    protected function makeUser(array $attributes = []): User
    {
        $branchScope = $attributes['branch_scope'] ?? 'all';
        $branch = $this->defaultBranch();
        $defaultBranchId = $attributes['default_branch_id'] ?? $branch->id;

        $user = User::create([
            'company_id' => Company::current()?->id,
            'name' => $attributes['name'] ?? 'Test Person',
            'email' => $attributes['email'] ?? sprintf('person%d@instance.test', ++static::$userSeq),
            'phone' => $attributes['phone'] ?? null,
            'password' => $attributes['password'] ?? self::ADMIN_PASSWORD,
            'status' => $attributes['status'] ?? 'active',
            'branch_scope' => $branchScope,
            'default_branch_id' => $defaultBranchId,
            'must_change_password' => $attributes['must_change_password'] ?? false,
            'password_changed_at' => now(),
        ]);

        if ($branchScope === 'assigned') {
            $user->branchAssignments()->syncWithoutDetaching([$defaultBranchId]);
        }

        return $user;
    }

    /** A non-system role holding the given permission keys (seed first). */
    protected function roleWith(array $permissionKeys, array $attributes = []): Role
    {
        static::$roleSeq++;

        $role = Role::create([
            'company_id' => Company::current()?->id,
            'name' => $attributes['name'] ?? 'Role '.static::$roleSeq,
            'slug' => $attributes['slug'] ?? 'role-'.static::$roleSeq,
            'description' => $attributes['description'] ?? null,
            'is_system' => false,
        ]);

        $ids = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return $role;
    }

    /**
     * Every flashed validation message as one searchable string.
     * Tolerant of the ViewErrorBag object AND Laravel 13's normalized
     * array shape (['default' => ['messages' => [...]]]).
     */
    protected function allFlashedErrors(): string
    {
        $errors = session('errors');

        if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
            return $errors->getBag('default')->toJson();
        }

        if (is_array($errors)) {
            return (string) json_encode($errors);
        }

        return '';
    }
}
