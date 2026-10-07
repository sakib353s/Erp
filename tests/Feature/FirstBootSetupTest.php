<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Platform\Services\SetupToken;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Spec §49 / Rule K: first-boot installer with a one-time hashed token,
 * no default password anywhere, and permanent closure after the first
 * company exists.
 */
class FirstBootSetupTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SetupToken::class)->consume(); // clean slate for this test class

        // Permission vocabulary is global (pre-company) — seed it so the
        // Administrator role materialises its full grant set.
        $this->seed(FoundationPermissionSeeder::class);
    }

    public function test_login_redirects_to_setup_before_a_company_exists(): void
    {
        $this->get('/login')->assertRedirect(route('setup.show'));
    }

    public function test_setup_screen_renders_before_a_company_exists(): void
    {
        $this->get('/setup')->assertOk();
    }

    public function test_invalid_setup_token_is_rejected_and_nothing_is_created(): void
    {
        app(SetupToken::class)->generate();

        $response = $this->post('/setup', $this->payload(str_repeat('f', 64)));

        $response->assertSessionHasErrors('token');
        $this->assertGuest();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_weak_admin_password_fails_the_real_password_policy(): void
    {
        $token = app(SetupToken::class)->generate();

        $payload = $this->payload($token);
        $payload['admin']['password'] = 'abc12';
        $payload['admin']['password_confirmation'] = 'abc12';

        $response = $this->post('/setup', $payload);

        $response->assertSessionHasErrors('admin.password');
        $this->assertGuest();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_valid_setup_bootstraps_company_admin_and_branch_then_closes_forever(): void
    {
        $token = app(SetupToken::class)->generate();

        $response = $this->post('/setup', $this->payload($token));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('companies', ['name' => 'Nile Fashions Ltd']);

        $admin = User::query()->where('email', 'owner@instance.test')->firstOrFail();

        // Mirror the next real request: context now carries a user, whose
        // all-branch scope makes the head-office row visible.
        $this->bindTenantContext($admin);

        $this->assertTrue((bool) $admin->is_super_admin);
        $this->assertSame('all', $admin->branch_scope);
        $this->assertNotNull($admin->company_id);
        $this->assertNotNull($admin->password_changed_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(self::ADMIN_PASSWORD, $admin->password));

        // The real Administrator role materialised with EVERY seeded grant.
        $adminRole = $admin->roles()->where('slug', 'administrator')->first();
        $this->assertNotNull($adminRole);
        $this->assertSame(
            \App\Domain\Foundation\Permission::query()->count(),
            $adminRole->permissions()->count(),
        );
        $this->assertGreaterThan(0, $adminRole->permissions()->count());

        // Exactly one head-office branch, flagged as the default.
        $this->assertDatabaseCount('branches', 1);
        $ho = Branch::query()->where('code', 'HO')->firstOrFail();
        $this->assertTrue((bool) $ho->is_default);

        // Setup closes permanently: GuardSetup answers 403 from now on.
        $this->get('/setup')->assertForbidden();
        $this->post('/setup', $this->payload($token))->assertForbidden();
        $this->assertDatabaseCount('companies', 1);
    }

    /** @return array<string, mixed> */
    private function payload(string $token): array
    {
        return [
            'token' => $token,
            'company' => ['name' => 'Nile Fashions Ltd'],
            'admin' => [
                'name' => 'Instance Owner',
                'email' => 'owner@instance.test',
                'password' => self::ADMIN_PASSWORD,
                'password_confirmation' => self::ADMIN_PASSWORD,
            ],
        ];
    }
}
