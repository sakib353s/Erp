<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * User administration over real HTTP: creation with role assignment and
 * forced password change, server-side scope-widening guards, the real
 * configurable PasswordPolicy, self-deletion guard, blank-password-keeps.
 */
class UserAdministrationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rafiq Hasan',
            'email' => 'rafiq@instance.test',
            'phone' => '01711000000',
            'status' => 'active',
            'branch_scope' => 'assigned',
            'default_branch_id' => $this->defaultBranch()->id,
            'branch_ids' => [$this->defaultBranch()->id],
            'password' => self::ADMIN_PASSWORD,
            'password_confirmation' => self::ADMIN_PASSWORD,
        ], $overrides);
    }

    public function test_store_creates_a_user_with_roles_and_forces_a_password_change(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $role = $this->roleWith(['users.view']);

        $r = $this->actingAs($admin)->post(route('users.store'), $this->payload([
            'roles' => [$role->id],
        ]));

        $user = User::query()->where('email', 'rafiq@instance.test')->firstOrFail();

        $r->assertRedirect(route('users.show', $user));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue($user->isActive());
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $user->password));
        $this->assertSame($ho->id, (int) $user->default_branch_id);
        $this->assertSame(1, $user->roles()->count());
        $this->assertTrue($user->roles->first()->is($role));
        $this->assertDatabaseHas('audit_events', ['entity_type' => 'user']);
    }

    public function test_store_refuses_scope_widening_beyond_the_actor(): void
    {
        $admin = $this->bootInstance();

        // A branch-limited actor with user-creation rights…
        $role = $this->roleWith(['portal.erp.access', 'users.create']);
        $limited = $this->makeUser(['branch_scope' => 'assigned']);
        $limited->roles()->attach($role->id);

        $r = $this->actingAs($limited)->post(route('users.store'), $this->payload([
            'branch_scope' => 'all', // …may not widen to all-branch access
            'roles' => [$role->id],
        ]));

        $r->assertStatus(302);
        $this->assertStringContainsString('You cannot grant all-branch access.', $this->allFlashedErrors());
        $this->assertDatabaseMissing('users', ['email' => 'rafiq@instance.test']);
        $this->assertNotNull($limited->fresh());
        $this->assertNotNull($admin->fresh());
    }

    public function test_weak_password_is_refused_and_nothing_is_written(): void
    {
        $admin = $this->bootInstance();
        $role = $this->roleWith(['users.view']);

        $this->actingAs($admin)->post(route('users.store'), $this->payload([
            'roles' => [$role->id],
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
        ]))->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'rafiq@instance.test']);
    }

    public function test_the_actor_can_never_delete_their_own_account(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertStatus(422);

        $this->assertNotNull(User::query()->find($admin->id));
    }

    public function test_update_without_a_password_keeps_the_existing_hash(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $role = $this->roleWith(['users.view']);
        $target = $this->makeUser();
        $hashBefore = $target->password;

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => 'Renamed Person',
            'email' => $target->email,
            'status' => 'active',
            'branch_scope' => 'assigned',
            'default_branch_id' => $ho->id,
            'branch_ids' => [$ho->id],
            'roles' => [$role->id],
            // password intentionally omitted → must be kept
        ])->assertStatus(302);

        $target->refresh();

        $this->assertSame('Renamed Person', $target->name);
        $this->assertSame($hashBefore, $target->password);
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $target->password));
    }
}
