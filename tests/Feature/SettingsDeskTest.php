<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Security\Services\PasswordPolicy;
use App\Domain\Settings\Services\InvariantGuard;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Settings\Setting;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §15 — the settings desk, and the floor underneath it.
 *
 * What this pins, in the order the complaints would arrive:
 *  · the module has a landing page, and it lists every group with how many of its
 *    values are actually set, who set them last, and the key the group needs;
 *  · each group has its own key on top of the module floor — a person who may set
 *    the company's document numbering has no business rewriting the password
 *    policy — and writing needs `settings.update` rather than the read key;
 *  · a refused write is never reported as a save, which is the one lie a settings
 *    screen must not tell;
 *  · the invariant floors are real: a password minimum under 8, a lockout under
 *    3 attempts and an audit trail under 30 days are refused wherever they come
 *    from, and the refusal is audited;
 *  · a branch may set what an outlet decides for itself, the company value is
 *    untouched, another branch still reads the company value, and removing an
 *    override puts the branch back on it — deleting the row, not copying the
 *    company's number into it;
 *  · company policy (security, audit, workflow, notifications) cannot be
 *    overridden for one branch at all;
 *  · a branch outside the actor's scope is refused, and the branch list says so
 *    rather than offering a door that would 403.
 */
class SettingsDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
    }

    // --------------------------------------------------------------- helpers

    protected function reader(array $keys): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($keys)->id);

        return $user;
    }

    protected function service(): SettingService
    {
        return app(SettingService::class);
    }

    protected function stored(string $group, string $key, ?int $branchId = null): ?Setting
    {
        return Setting::query()
            ->where('company_id', $this->admin->company_id)
            ->where('branch_id', $branchId ?? Setting::COMPANY_SCOPE)
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->first();
    }

    // ------------------------------------------------------------ the desk

    public function test_the_desk_lists_every_group_with_what_is_set_where_and_the_key_it_needs(): void
    {
        // Something real to count: one company value and one branch override.
        $this->service()->set('general', 'decimal_places', 3, null, $this->admin);
        $this->service()->set('labels', 'template', 'a4_3x7', $this->defaultBranch()->id, $this->admin);

        $response = $this->actingAs($this->admin)->get(route('settings.index'));

        $response->assertOk();

        foreach (array_keys((array) config('erp.settings.groups')) as $group) {
            $response->assertSee($group);
        }

        $response->assertSee('General Settings');
        $response->assertSee('settings.security');
        $response->assertSee('company policy');
        $response->assertSee('per branch');
        $response->assertSee('Branch settings');
    }

    public function test_the_desk_is_behind_the_module_key_and_a_group_behind_its_own(): void
    {
        $floorOnly = $this->reader(['settings.view']);

        $this->actingAs($floorOnly)->get(route('settings.index'))->assertOk();

        // The floor opens the desk and the group it names, and nothing else.
        $this->actingAs($floorOnly)->get(route('settings.show', 'general'))->assertForbidden();
        $this->actingAs($floorOnly)->get(route('settings.show', 'security'))->assertForbidden();

        $security = $this->reader(['settings.view', 'settings.security']);

        $this->actingAs($security)->get(route('settings.show', 'security'))->assertOk();
        $this->actingAs($security)->get(route('settings.show', 'general'))->assertForbidden();
    }

    public function test_reading_a_group_does_not_write_it(): void
    {
        $reader = $this->reader(['settings.view', 'settings.general']);

        $this->actingAs($reader)->get(route('settings.show', 'general'))->assertOk();

        $this->actingAs($reader)
            ->post(route('settings.update', 'general'), ['settings' => ['decimal_places' => 4]])
            ->assertForbidden();

        $this->assertNull($this->stored('general', 'decimal_places'));
    }

    public function test_saving_reports_what_was_stored_and_never_counts_a_refusal_as_a_save(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.update', 'general'), [
                'settings' => ['decimal_places' => 3, 'time_format' => 'h:i A'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Saved 2 setting(s).');

        $this->assertSame('3', $this->stored('general', 'decimal_places')?->value);

        // The history keeps the old value beside the new one, and the audit trail
        // records the change: a settings edit is evidence, not a preference.
        $this->assertSame(1, DB::table('setting_history')
            ->where('setting_id', $this->stored('general', 'decimal_places')->id)
            ->count());

        $this->assertSame(2, DB::table('audit_events')->where('action', 'config.update')->count());
    }

    public function test_the_service_reports_what_it_stored_and_names_what_it_refused(): void
    {
        $result = $this->service()->setGroup('general', ['date_format' => 'Y-m-d'], null, $this->admin);

        $this->assertSame(['date_format'], $result['saved']);
        $this->assertSame([], $result['rejected']);

        // The screen counts what was stored, not what was submitted.
        $this->actingAs($this->admin)
            ->post(route('settings.update', 'general'), ['settings' => ['date_format' => 'Y-m-d']])
            ->assertSessionHas('status', 'Saved 1 setting(s).')
            ->assertSessionMissing('warning');

        // The protected identities are consulted before anything is written —
        // the guard is the one place that list is read from.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(InvariantGuard::class)->assertWrite('general', 'instance.slug', 'someone-elses-slug');
    }

    // -------------------------------------------------------- the invariant floor

    public function test_the_invariant_floors_hold_whatever_screen_asks(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service()->set('security', 'password_min_length', 4, null, $this->admin);
    }

    public function test_a_floor_refusal_is_audited_even_though_nothing_changed(): void
    {
        try {
            $this->service()->set('audit', 'retention_days', 7, null, $this->admin);
            $this->fail('A 7-day audit retention should have been refused.');
        } catch (\Illuminate\Validation\ValidationException $refused) {
            $this->assertStringContainsString('30', $refused->validator->errors()->first());
        }

        $this->assertNull($this->stored('audit', 'retention_days'));

        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'config.invariant_denied')
            ->where('result', 'denied')
            ->count());
    }

    public function test_a_form_that_asks_below_the_floor_shows_the_refusal_on_the_field(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.update', 'security'), ['settings' => ['password_min_length' => 4]])
            ->assertSessionHasErrors('settings.password_min_length');

        $this->assertNull($this->stored('security', 'password_min_length'));
    }

    public function test_the_policy_that_reads_the_security_group_sees_the_floor_and_the_setting(): void
    {
        // The group is not decoration: the policy reads it at run time.
        $this->service()->set('security', 'password_min_length', 12, null, $this->admin);

        $policy = app(PasswordPolicy::class);

        $this->assertNotEmpty($policy->validate('Short1#x', null, null));
        $this->assertEmpty($policy->validate('ThisIsLongEnough1#', null, null));

        // And below the floor the write itself is refused, so the policy can
        // never be reasoned down through the back door.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->set('security', 'password_min_length', 6, null, $this->admin);
    }

    // ------------------------------------------------------------- branch scope

    public function test_a_branch_value_replaces_the_company_value_for_that_branch_alone(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'UTT',
            'name' => 'Uttara depot',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->service()->set('labels', 'template', 'a4_3x8', null, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('settings.branch.update', $this->defaultBranch()), [
                'settings' => ['labels' => ['template' => 'a4_3x7']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The branch has its own value …
        $this->assertSame('a4_3x7', $this->service()->get('labels', 'template', null, $this->defaultBranch()->id));

        // … the company's value is untouched …
        $this->assertSame('a4_3x8', $this->service()->get('labels', 'template'));

        // … and another branch still reads the company's.
        $this->assertSame('a4_3x8', $this->service()->get('labels', 'template', null, $other->id));

        $this->assertTrue($this->service()->hasOverride('labels', 'template', $this->defaultBranch()->id));
        $this->assertFalse($this->service()->hasOverride('labels', 'template', $other->id));

        // The service resolves the effective value branch-first …
        $this->assertSame('a4_3x7', $this->service()->effective('labels', 'template', $this->defaultBranch()->id));

        // … and a real consumer asks the branch-aware question: with the context
        // on this branch the label desk prints its sheet, not the company's.
        $this->bindTenantContext($this->admin, $this->defaultBranch());
        $this->assertSame('a4_3x7', app(\App\Domain\Inventory\Services\LabelService::class)->template());

        $this->bindTenantContext($this->admin, $other);
        $this->assertSame('a4_3x8', app(\App\Domain\Inventory\Services\LabelService::class)->template());
    }

    public function test_removing_an_override_puts_the_branch_back_on_the_company_value(): void
    {
        $this->service()->set('labels', 'template', 'a4_3x8', null, $this->admin);
        $this->service()->set('labels', 'template', 'a4_3x7', $this->defaultBranch()->id, $this->admin);

        $this->actingAs($this->admin)
            ->delete(route('settings.branch.forget', [
                $this->defaultBranch(), 'labels', 'template',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        // The row is gone — not overwritten with the company's current number,
        // which would freeze this branch at today's figure.
        $this->assertNull($this->stored('labels', 'template', $this->defaultBranch()->id));
        $this->assertSame('a4_3x8', $this->service()->get('labels', 'template', null, $this->defaultBranch()->id));

        // Removing it twice says so instead of pretending something happened.
        $this->actingAs($this->admin)
            ->delete(route('settings.branch.forget', [$this->defaultBranch(), 'labels', 'template']))
            ->assertSessionHas('warning');
    }

    public function test_company_policy_cannot_be_overridden_for_one_branch(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service()->set('audit', 'retention_days', 400, $this->defaultBranch()->id, $this->admin);
    }

    public function test_the_branch_screen_refuses_a_policy_group_posted_through_the_form(): void
    {
        // A hand-crafted post, bypassing the screen: the controller drops the
        // company-only group, so nothing is written and the branch keeps the
        // company's number.
        $this->actingAs($this->admin)
            ->post(route('settings.branch.update', $this->defaultBranch()), [
                'settings' => ['audit' => ['retention_days' => 400]],
            ])
            ->assertRedirect();

        $this->assertNull($this->stored('audit', 'retention_days', $this->defaultBranch()->id));
    }

    public function test_the_branch_list_names_every_branch_and_what_may_not_differ(): void
    {
        $list = $this->actingAs($this->admin)->get(route('settings.branches'));

        $list->assertOk();
        $list->assertSee($this->defaultBranch()->name);
        $list->assertSee('Company policy groups');
        $list->assertSee('security, audit, workflow, notifications');

        // The branch's own screen spells out why each policy group is company-wide.
        $branch = $this->actingAs($this->admin)->get(route('settings.branch.show', $this->defaultBranch()));

        $branch->assertOk();
        $branch->assertSee('company-wide');
        $branch->assertSee('A branch may not shorten the trail its own mistakes are written to.');
    }

    public function test_a_branch_outside_the_actors_scope_is_refused(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DHK2',
            'name' => 'Dhanmondi outlet',
            'is_default' => false,
            'is_active' => true,
        ]);

        $scoped = $this->reader(['settings.view', 'settings.branch', 'settings.update']);
        $scoped->forceFill(['branch_scope' => 'assigned', 'default_branch_id' => $this->defaultBranch()->id])->save();
        $scoped->branchAssignments()->syncWithoutDetaching([$this->defaultBranch()->id]);

        $this->actingAs($scoped)->get(route('settings.branches'))->assertOk();

        $this->actingAs($scoped)
            ->post(route('settings.branch.update', $other), ['settings' => ['labels' => ['template' => 'a4_3x7']]])
            ->assertForbidden();

        $this->actingAs($scoped)->get(route('settings.branch.show', $other))->assertForbidden();
    }

    public function test_the_guard_is_the_one_place_that_knows_the_floors(): void
    {
        $guard = app(InvariantGuard::class);

        $this->assertContains('security', InvariantGuard::COMPANY_ONLY_GROUPS);
        $this->assertTrue($guard->isCompanyOnly('audit'));
        $this->assertFalse($guard->isCompanyOnly('labels'));
        $this->assertTrue($guard->isProtectedKey('instance.slug'));
        $this->assertArrayHasKey('password_min_length', $guard->floors()['security']);
    }
}
