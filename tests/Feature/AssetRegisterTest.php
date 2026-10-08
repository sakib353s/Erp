<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\JournalLine;
use App\Domain\Business\AssetRegistry;
use App\Domain\Business\BusinessAsset;
use App\Domain\Business\BusinessRecord;
use App\Domain\Business\VehicleTrip;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-14 — the asset register, depreciation and the trip log.
 *
 * What is pinned here, in the order somebody who owns the books would ask:
 *
 *  · **a vehicle is an asset** — one row in one table, with plates; register one
 *    and the register, the vehicle shelf and the trip log all agree;
 *  · **book value follows the ledger** — `accumulated_depreciation` moves only
 *    when a real journal entry posted, never because a page said so. The tests
 *    assert the register's figure against the debit lines of the entries, which
 *    is the only assertion that matters about this module;
 *  · **a month is charged once** — a run posts one entry per asset per month,
 *    catches up on months it missed, and cannot charge a month that has not
 *    ended or one it has already charged;
 *  · **nothing is invented** — a distance with no odometer readings stays blank,
 *    a cost that was never capitalised cannot be depreciated, and a refused
 *    action says why;
 *  · **the register is guarded** — 403 without the right, 404 across companies,
 *    and reading is not writing.
 */
class AssetRegisterTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed "today" before anything is seeded, so the fiscal periods the
        // accounting seeder opens cover the months these tests post into. A test
        // clock that moved with the real one would eventually post a charge into a
        // fiscal year nobody opened.
        Carbon::setTestNow(Carbon::parse('2027-02-20 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->headOffice = $this->defaultBranch();
        $this->bindTenantContext($this->admin, $this->headOffice);

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(AccountingCoreSeeder::class);

        // The fiscal year the charges will land in, opened explicitly. The
        // structural seeder only ensures periods for a year that already exists,
        // and a test that posts into a closed period would be testing the posting
        // gate rather than depreciation.
        $fiscalYear = FiscalYear::query()
            ->where('company_id', Company::current()?->id)
            ->where('is_current', true)
            ->first();

        if ($fiscalYear === null) {
            $fiscalYear = FiscalYear::create([
                'company_id' => Company::current()?->id,
                'code' => 'FY2026',
                'name' => 'FY 2026-2027',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'status' => 'open',
                'is_current' => true,
            ]);
        }

        app(FiscalPeriodService::class)->ensurePeriods($fiscalYear);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* --------------------------------------------------------------- helpers */

    protected function viewer(User $user): void
    {
        $user->roles()->attach($this->roleWith(['business.assets.view'])->id);
    }

    protected function keeper(User $user): void
    {
        $user->roles()->attach($this->roleWith(['business.assets.view', 'business.assets.manage'])->id);
    }

    /**
     * An asset registered through the real screen.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function probe(array $overrides = []): BusinessAsset
    {
        $payload = array_merge([
            'category' => 'equipment',
            'name' => 'Adjustment probe',
            'condition' => 'good',
            'acquired_on' => now()->subMonths(6)->toDateString(),
            'acquisition_cost' => '120000.00',
            'location' => 'Head office — 2nd floor',
            'branch_id' => $this->headOffice->id,
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 24,
            'salvage_value' => '0',
        ], $overrides);

        $response = $this->actingAs($this->manager())->post(route('assets.store'), $payload);
        $response->assertRedirect();

        return BusinessAsset::query()->orderByDesc('id')->firstOrFail();
    }

    protected function manager(): User
    {
        $manager = $this->makeUser(['name' => 'Asset Keeper']);
        $this->keeper($manager);

        return $manager;
    }

    /** Put the cost in the books, as the desk does. */
    protected function capitalise(BusinessAsset $asset, ?string $on = null): BusinessAsset
    {
        $this->actingAs($this->manager())
            ->post(route('assets.capitalise', $asset), ['capitalised_on' => $on ?? now()->toDateString()])
            ->assertRedirect();

        return $asset->refresh();
    }

    /** The register's own reading of what the ledger charged this asset. */
    protected function ledgerCharged(BusinessAsset $asset): float
    {
        return round((float) JournalLine::query()
            ->where('dc', JournalLine::DEBIT)
            ->whereIn('journal_entry_id', JournalEntry::query()
                ->where('source_type', 'business_asset')
                ->where('source_id', $asset->id)
                ->select('id'))
            ->sum('amount'), 2);
    }

    /* ------------------------------------------------------------ registering */

    public function test_an_asset_is_registered_through_the_screen_with_its_own_code(): void
    {
        $asset = $this->probe(['name' => 'Sewing machine 4']);

        $this->assertSame('AST-000001', $asset->code);
        $this->assertSame('equipment', $asset->category);
        $this->assertSame(BusinessAsset::STATUS_IN_USE, $asset->status);
        $this->assertSame('120000.00', (string) $asset->acquisition_cost);
        $this->assertSame(24, $asset->useful_life_months);

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $asset->id,
            'action' => 'created',
            'actor_id' => $asset->created_by,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.asset_registered',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
        ]);
    }

    public function test_every_asset_gets_its_own_code(): void
    {
        $first = $this->probe(['name' => 'First']);
        $second = $this->probe(['name' => 'Second']);

        $this->assertSame('AST-000001', $first->code);
        $this->assertSame('AST-000002', $second->code);
    }

    public function test_a_vehicle_must_carry_its_registration_number(): void
    {
        $this->actingAs($this->manager())
            ->post(route('assets.store'), [
                'category' => 'vehicle',
                'name' => 'Van without plates',
                'depreciation_method' => 'straight_line',
                'useful_life_months' => 96,
            ])
            ->assertSessionHasErrors('registration_no');

        $this->assertSame(0, BusinessAsset::query()->count());

        // With plates it goes through — the field is required, not refused.
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Hiace delivery van',
            'registration_no' => 'Dhaka Metro-Ga 11-2345',
            'odometer_reading' => 84000,
        ]);

        $this->assertTrue($van->isVehicle());
        $this->assertSame('Dhaka Metro-Ga 11-2345', $van->registration_no);
    }

    public function test_a_vehicle_field_is_not_stored_for_something_that_is_not_a_vehicle(): void
    {
        $laptop = $this->probe([
            'category' => 'computer',
            'name' => 'Warehouse laptop',
            'registration_no' => 'Dhaka Metro-Ga 11-9999',
            'odometer_reading' => 40000,
        ]);

        $this->assertNull($laptop->registration_no);
        $this->assertNull($laptop->odometer_reading);
    }

    public function test_a_vehicle_with_neither_method_nor_life_still_lands_on_the_register(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Pickup',
            'registration_no' => 'Dhaka Metro-Ga 12-0001',
            'depreciation_method' => null,
            'useful_life_months' => null,
        ]);

        // The registry's default life for a vehicle is 96 months; the point is
        // that "no decision" is stored as the registry's default rather than as
        // a null nobody will ever notice.
        $this->assertSame(96, $van->useful_life_months);
    }

    /* --------------------------------------------------------------- guarding */

    public function test_reading_needs_the_view_permission_and_writing_needs_manage(): void
    {
        $stranger = $this->makeUser();
        $this->actingAs($stranger)->get(route('assets.index'))->assertForbidden();

        $viewer = $this->makeUser(['name' => 'Reader']);
        $this->viewer($viewer);
        $this->actingAs($viewer)->get(route('assets.index'))->assertOk();
        $this->actingAs($viewer)->get(route('assets.vehicles'))->assertOk();
        $this->actingAs($viewer)->get(route('assets.equipment'))->assertOk();
        $this->actingAs($viewer)->get(route('assets.trips'))->assertOk();
        $this->actingAs($viewer)->get(route('assets.depreciation'))->assertOk();
        $this->actingAs($viewer)->get(route('assets.disposal'))->assertOk();

        // Reading is not writing.
        $this->actingAs($viewer)
            ->post(route('assets.store'), ['category' => 'other', 'name' => 'Sneaky'])
            ->assertForbidden();

        $asset = $this->probe();
        $this->actingAs($viewer)->get(route('assets.show', $asset))->assertOk();
        $this->actingAs($viewer)->post(route('assets.capitalise', $asset), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('assets.dispose', $asset), ['disposal_reason' => 'nope'])->assertForbidden();
        $this->actingAs($viewer)->post(route('assets.depreciation.run'))->assertForbidden();
    }

    public function test_another_company_s_asset_is_not_found(): void
    {
        $asset = $this->probe();

        $outsider = User::query()->create([
            'company_id' => Company::query()->create([
                'name' => 'Another Company', 'singleton' => 'other', 'is_active' => true,
            ])->id,
            'name' => 'Outsider',
            'email' => 'outsider@elsewhere.test',
            'password' => self::ADMIN_PASSWORD,
            'status' => 'active',
            'branch_scope' => 'all',
        ]);
        $this->keeper($outsider);
        $this->bindTenantContext($outsider);

        $this->actingAs($outsider)->get(route('assets.show', $asset))->assertNotFound();
        $this->actingAs($outsider)->put(route('assets.update', $asset), ['name' => 'Stolen'])->assertNotFound();
    }

    public function test_an_asset_on_a_branch_this_person_cannot_see_is_not_found(): void
    {
        $otherBranch = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Chattogram depot',
            'code' => 'CTG',
            'is_default' => false,
            'is_active' => true,
        ]);

        $asset = $this->probe(['branch_id' => $otherBranch->id, 'name' => 'Chattogram freezer']);

        $restricted = $this->makeUser(['name' => 'Depot keeper', 'branch_scope' => 'assigned']);
        $this->keeper($restricted);
        $this->bindTenantContext($restricted, $this->headOffice);

        $this->actingAs($restricted)->get(route('assets.show', $asset))->assertNotFound();
    }

    /* ----------------------------------------------------------- depreciation */

    public function test_depreciation_is_refused_until_the_cost_is_in_the_books(): void
    {
        $asset = $this->probe();

        // Not capitalised: the desk says so and the run charges nothing.
        $this->assertFalse($asset->isCapitalised());

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();
        $this->assertSame(0, JournalEntry::query()->count());

        $this->actingAs($this->manager())
            ->get(route('assets.depreciation'))
            ->assertOk()
            ->assertSee('Cost recorded, not in the books yet');

        $this->capitalise($asset);

        $this->assertNotNull($asset->capitalised_at);
        $this->assertNotNull($asset->depreciation_starts_on);
        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $asset->id,
            'action' => 'capitalised',
        ]);
    }

    public function test_an_asset_with_no_cost_cannot_be_capitalised(): void
    {
        $asset = $this->probe(['acquisition_cost' => null]);

        $this->actingAs($this->manager())
            ->post(route('assets.capitalise', $asset), [])
            ->assertSessionHasErrors('acquisition_cost');

        $this->assertNull($asset->fresh()->capitalised_at);
    }

    public function test_the_depreciation_run_posts_one_entry_per_completed_month_and_the_register_follows(): void
    {
        // Bought and capitalised at the start of three months ago: March, April
        // and May are complete by the end of May.
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe(['acquisition_cost' => '120000.00', 'useful_life_months' => 24]);
        $this->capitalise($asset, '2026-12-01');

        $asset->refresh();
        $this->assertSame('2026-12-01', $asset->depreciation_starts_on->toDateString());

        $this->actingAs($this->manager())
            ->post(route('assets.depreciation.run'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $entries = JournalEntry::query()
            ->where('source_type', 'business_asset')
            ->where('source_id', $asset->id)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(3, $entries, 'March, April and May should each have their own entry');

        $this->assertSame(
            ['asset_depreciation_2026-12', 'asset_depreciation_2027-01', 'asset_depreciation_2027-02'],
            $entries->pluck('source_event')->all(),
        );

        foreach ($entries as $entry) {
            $this->assertSame('depreciation', $entry->journal_type);

            $debit = JournalLine::query()->where('journal_entry_id', $entry->id)->where('dc', JournalLine::DEBIT)->firstOrFail();
            $credit = JournalLine::query()->where('journal_entry_id', $entry->id)->where('dc', JournalLine::CREDIT)->firstOrFail();

            $this->assertSame(AssetRegistry::DEPRECIATION_EXPENSE_CODE, Account::query()->find($debit->account_id)?->code);
            $this->assertSame(AssetRegistry::ACCUMULATED_DEPRECIATION_CODE, Account::query()->find($credit->account_id)?->code);
            $this->assertSame((string) $debit->amount, (string) $credit->amount);
        }

        $asset->refresh();

        // 120,000 over 24 months is 5,000 a month, three months = 15,000.
        $this->assertSame(15000.0, (float) $asset->accumulated_depreciation);
        $this->assertSame(105000.0, (float) $asset->bookValue());
        $this->assertSame('2027-02-28', $asset->last_depreciated_on->toDateString());

        // The register's figure is the ledger's figure — not a parallel number.
        $this->assertSame(15000.0, $this->ledgerCharged($asset));

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $asset->id,
            'action' => 'depreciated',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.asset_depreciated',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
        ]);
    }

    public function test_running_the_depreciation_twice_cannot_charge_the_same_month_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe(['acquisition_cost' => '120000.00', 'useful_life_months' => 24]);
        $this->capitalise($asset, '2027-02-01');

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();
        $this->assertSame(1, JournalEntry::query()->count());

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();
        $this->assertSame(1, JournalEntry::query()->count());

        $this->assertSame(5000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    public function test_a_month_that_has_not_ended_is_not_charged(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-15 09:00:00'));

        $asset = $this->probe(['acquisition_cost' => '120000.00', 'useful_life_months' => 24]);
        $this->capitalise($asset, '2027-02-10');

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0.0, (float) $asset->fresh()->accumulated_depreciation);

        // The desk says when it will fall due rather than showing a charge that
        // has already been taken.
        $this->actingAs($this->manager())
            ->get(route('assets.depreciation'))
            ->assertOk()
            ->assertSee('Nothing is due');
    }

    public function test_a_charge_is_dated_the_end_of_the_month_it_covers_and_never_in_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 07:00:00'));

        $asset = $this->probe(['acquisition_cost' => '60000.00', 'useful_life_months' => 12]);
        $this->capitalise($asset, '2027-02-04');

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();

        $entry = JournalEntry::query()->firstOrFail();

        // May is complete on the 1st of June: the charge is dated 31 May, not the
        // day the command ran and not the end of June.
        $this->assertSame('2027-02-28', $entry->entry_date->toDateString());
        $this->assertSame('asset_depreciation_2027-02', $entry->source_event);
    }

    public function test_depreciation_stops_at_the_salvage_value(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe(['acquisition_cost' => '60000.00', 'useful_life_months' => 12, 'salvage_value' => '15000']);
        $this->capitalise($asset, '2027-02-01');

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();

        $asset->refresh();

        $this->assertSame(45000.0, $asset->depreciableBase());
        $this->assertSame(3750.0, (float) $asset->accumulated_depreciation);
        $this->assertSame(56250.0, (float) $asset->bookValue());

        // A fully salvaged asset has nothing left: the run says so and posts nothing.
        $asset->forceFill(['accumulated_depreciation' => 45000])->save();

        Carbon::setTestNow(Carbon::parse('2027-03-31 09:00:00'));

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'))->assertRedirect();

        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_fully_depreciated_asset_never_goes_below_its_salvage_value(): void
    {
        $asset = $this->probe(['acquisition_cost' => '30000.00', 'useful_life_months' => 10, 'salvage_value' => '5000']);

        $asset->forceFill(['accumulated_depreciation' => 99999])->save();

        $this->assertSame(5000.0, $asset->fresh()->bookValue());
        $this->assertSame(0.0, $asset->fresh()->remainingDepreciable());
        $this->assertNull($asset->fresh()->nextDepreciationOn());
    }

    public function test_the_depreciation_command_posts_and_can_be_dry_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe(['acquisition_cost' => '48000.00', 'useful_life_months' => 12]);
        $this->capitalise($asset, '2027-02-01');

        $this->artisan('erp:business:asset-depreciation', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, JournalEntry::query()->count());

        $this->artisan('erp:business:asset-depreciation')
            ->expectsOutputToContain('Posted')
            ->assertSuccessful();

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(4000.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    public function test_the_command_says_so_when_the_depreciation_accounts_are_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe();
        $this->capitalise($asset, '2027-02-01');

        // A chart of accounts without 5270 is a seeding problem, and the command
        // should say that rather than post somewhere approximate.
        Account::query()->where('code', AssetRegistry::DEPRECIATION_EXPENSE_CODE)->delete();

        $this->artisan('erp:business:asset-depreciation')->assertFailed();

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_the_desk_names_what_is_waiting_and_what_has_no_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $waiting = $this->probe(['name' => 'Not yet in the books']);
        $this->probe(['name' => 'No policy', 'depreciation_method' => 'none', 'useful_life_months' => null]);

        $this->capitalise($this->probe(['name' => 'Charged up']), '2027-02-01');

        $this->actingAs($this->manager())
            ->get(route('assets.depreciation'))
            ->assertOk()
            ->assertSee($waiting->name)  // named in the waiting list
            ->assertSee('No policy yet')
            ->assertSee('Capitalised, with no depreciation policy');
    }

    /* ------------------------------------------------------------ the trips */

    public function test_a_trip_with_both_odometer_readings_records_a_measured_distance(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Hiace 2',
            'registration_no' => 'Dhaka Metro-Ga 11-3000',
            'odometer_reading' => 84000,
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.trips.log'), [
                'business_asset_id' => $van->id,
                'trip_date' => now()->toDateString(),
                'from_location' => 'Head office',
                'to_location' => 'Uttara depot',
                'purpose' => 'Deliver order 4412',
                'odometer_start' => 84000,
                'odometer_end' => 84127,
                'fuel_litres' => '12.500',
                'fuel_cost' => '1600.00',
                'other_cost' => '120.00',
            ])
            ->assertRedirect();

        $trip = VehicleTrip::query()->firstOrFail();

        $this->assertSame(127.0, (float) $trip->distance_km);
        $this->assertSame(1720.0, $trip->totalCost());
        $this->assertSame(13.54, $trip->costPerKm());
        $this->assertSame(10.16, $trip->fuelEfficiency());
        $this->assertSame('Head office → Uttara depot', $trip->routeLabel());
        $this->assertFalse($trip->isExpensed());

        // The vehicle's odometer follows the trips rather than being retyped.
        $this->assertSame(84127, $van->fresh()->odometer_reading);

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $van->id,
            'action' => 'trip',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.vehicle_trip_logged',
            'entity_id' => $van->id,
        ]);
    }

    public function test_a_trip_without_odometer_readings_keeps_the_distance_empty_rather_than_guessed(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Pickup',
            'registration_no' => 'Dhaka Metro-Ga 11-4000',
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.trips.log'), [
                'business_asset_id' => $van->id,
                'trip_date' => now()->toDateString(),
                'fuel_cost' => '800.00',
            ])
            ->assertRedirect();

        $trip = VehicleTrip::query()->firstOrFail();

        $this->assertNull($trip->distance_km);
        $this->assertNull($trip->costPerKm());
        $this->assertSame(800.0, $trip->totalCost());

        // The idle reading is left exactly as it was — no trip, no invented
        // odometer movement.
        $this->assertNull($van->fresh()->odometer_reading);
    }

    public function test_a_trip_cannot_close_below_its_opening_reading(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Van',
            'registration_no' => 'Dhaka Metro-Ga 11-5000',
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.trips.log'), [
                'business_asset_id' => $van->id,
                'trip_date' => now()->toDateString(),
                'odometer_start' => 50000,
                'odometer_end' => 49000,
            ])
            ->assertSessionHasErrors('odometer_end');

        $this->assertSame(0, VehicleTrip::query()->count());
    }

    public function test_a_laptop_cannot_make_a_trip(): void
    {
        $laptop = $this->probe(['category' => 'computer', 'name' => 'Laptop']);

        $this->actingAs($this->manager())
            ->post(route('assets.trips.log'), [
                'business_asset_id' => $laptop->id,
                'trip_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('business_asset_id');

        $this->assertSame(0, VehicleTrip::query()->count());
    }

    public function test_a_vehicle_that_has_been_written_off_cannot_make_a_trip(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Old van',
            'registration_no' => 'Dhaka Metro-Ga 11-6000',
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.dispose', $van), ['disposal_reason' => 'Sold to a trader'])
            ->assertRedirect();

        $this->actingAs($this->manager())
            ->post(route('assets.trips.log'), [
                'business_asset_id' => $van->id,
                'trip_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('business_asset_id');

        $this->assertSame(0, VehicleTrip::query()->count());
    }

    public function test_the_trip_log_totals_the_measured_kilometres(): void
    {
        // Pinned mid-month: the log's default range starts at the first of the
        // month, so a test that ran on the 1st would drop yesterday's trip.
        Carbon::setTestNow(Carbon::parse('2027-02-20 10:00:00'));

        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Hiace 3',
            'registration_no' => 'Dhaka Metro-Ga 11-7000',
        ]);

        foreach ([[100, 140, '600.00'], [140, 165, '450.00']] as $index => [$start, $end, $fuel]) {
            $this->actingAs($this->manager())->post(route('assets.trips.log'), [
                'business_asset_id' => $van->id,
                'trip_date' => now()->subDays($index)->toDateString(),
                'odometer_start' => $start,
                'odometer_end' => $end,
                'fuel_cost' => $fuel,
            ]);
        }

        $this->actingAs($this->manager())
            ->get(route('assets.trips'))
            ->assertOk()
            ->assertSee('65.00 km')
            ->assertSee('1,050.00');
    }

    public function test_the_vehicle_shelf_shows_what_the_fleet_cost_this_month(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Hiace 4',
            'registration_no' => 'Dhaka Metro-Ga 11-8000',
        ]);

        $this->actingAs($this->manager())->post(route('assets.trips.log'), [
            'business_asset_id' => $van->id,
            'trip_date' => now()->toDateString(),
            'odometer_start' => 1000,
            'odometer_end' => 1100,
            'fuel_cost' => '1200.00',
        ]);

        $this->actingAs($this->manager())
            ->get(route('assets.vehicles'))
            ->assertOk()
            ->assertSee('Hiace 4')
            ->assertSee('100.00 km')
            ->assertSee('৳12.00'); // cost per kilometre, a figure only the distance makes possible
    }

    /* -------------------------------------------------------- disposal & papers */

    public function test_writing_something_off_keeps_the_row_and_records_why(): void
    {
        $asset = $this->probe(['name' => 'Old generator']);
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('assets.dispose', $asset), [
                'disposed_on' => now()->toDateString(),
                'disposal_reason' => 'Beyond repair — sold for scrap',
                'disposal_proceeds' => '2500.00',
            ])
            ->assertRedirect();

        $asset->refresh();

        $this->assertTrue($asset->isDisposed());
        $this->assertSame('Beyond repair — sold for scrap', $asset->disposal_reason);
        $this->assertSame('2500.00', (string) $asset->disposal_proceeds);
        $this->assertSame($manager->id, $asset->disposed_by);

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $asset->id,
            'action' => 'disposed',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.asset_disposed',
            'entity_id' => $asset->id,
        ]);

        // Off the working register, on the disposal register.
        $this->actingAs($this->manager())
            ->get(route('assets.index'))
            ->assertOk()
            ->assertDontSee('Old generator');

        $this->actingAs($this->manager())
            ->get(route('assets.disposal'))
            ->assertOk()
            ->assertSee('Old generator')
            ->assertSee('Beyond repair — sold for scrap');
    }

    public function test_writing_something_off_twice_is_refused(): void
    {
        $asset = $this->probe();

        $this->actingAs($this->manager())
            ->post(route('assets.dispose', $asset), ['disposal_reason' => 'Sold'])
            ->assertRedirect();

        $this->actingAs($this->manager())
            ->post(route('assets.dispose', $asset), ['disposal_reason' => 'Sold again'])
            ->assertSessionHasErrors('disposal_reason');
    }

    public function test_a_paper_can_be_hung_on_a_vehicle_and_comes_back_on_the_vehicle_shelf(): void
    {
        $van = $this->probe([
            'category' => 'vehicle',
            'name' => 'Hiace 5',
            'registration_no' => 'Dhaka Metro-Ga 11-9000',
        ]);

        $fitness = BusinessRecord::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->headOffice->id,
            'kind' => 'certificate',
            'title' => 'Fitness certificate — Hiace 5',
            'reference_no' => 'FIT-2026-114',
            'status' => 'active',
            'expires_on' => now()->addDays(20)->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.records.link', $van), ['record_id' => $fitness->id])
            ->assertRedirect();

        $this->assertSame($van->id, $fitness->fresh()->business_asset_id);

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $van->id,
            'action' => 'record_linked',
        ]);

        $this->actingAs($this->manager())
            ->get(route('assets.vehicles'))
            ->assertOk()
            ->assertSee('Fitness certificate — Hiace 5');

        $this->actingAs($this->manager())
            ->get(route('assets.show', $van))
            ->assertOk()
            ->assertSee('Fitness certificate — Hiace 5');

        // Unlinking takes it off the asset and leaves the record alone.
        $this->actingAs($this->manager())
            ->delete(route('assets.records.unlink', [$van, $fitness]))
            ->assertRedirect();

        $this->assertNull($fitness->fresh()->business_asset_id);
        $this->assertDatabaseHas('business_records', ['id' => $fitness->id, 'status' => 'active']);
    }

    public function test_a_paper_already_on_another_asset_says_so_rather_than_being_stolen(): void
    {
        $first = $this->probe([
            'category' => 'vehicle', 'name' => 'Van A', 'registration_no' => 'Dhaka Metro-Ga 12-1000',
        ]);
        $second = $this->probe([
            'category' => 'vehicle', 'name' => 'Van B', 'registration_no' => 'Dhaka Metro-Ga 12-2000',
        ]);

        $paper = BusinessRecord::query()->create([
            'company_id' => $this->admin->company_id,
            'kind' => 'insurance',
            'title' => 'Insurance — Van A',
            'status' => 'active',
            'business_asset_id' => $first->id,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->manager())
            ->post(route('assets.records.link', $second), ['record_id' => $paper->id])
            ->assertSessionHasErrors('record_id');

        $this->assertSame($first->id, $paper->fresh()->business_asset_id);
    }

    /* ------------------------------------------------------------------ pages */

    public function test_the_register_shows_the_accumulated_figure_the_ledger_gave_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00'));

        $asset = $this->probe(['name' => 'Cold room', 'acquisition_cost' => '240000.00', 'useful_life_months' => 48]);
        $this->capitalise($asset, '2027-02-01');

        $this->actingAs($this->manager())->post(route('assets.depreciation.run'));

        $this->actingAs($this->manager())
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('Cold room')
            ->assertSee('5,000.00')      // the month charged
            ->assertSee('235,000.00');   // book value
    }

    public function test_the_add_asset_page_asks_what_kind_before_it_asks_anything_else(): void
    {
        $this->actingAs($this->manager())
            ->get(route('assets.create'))
            ->assertOk()
            ->assertSee('What kind of thing is it?')
            ->assertSee('Vehicle')
            ->assertSee('Computers &amp; IT', false);

        $this->actingAs($this->manager())
            ->get(route('assets.create', ['category' => 'vehicle']))
            ->assertOk()
            ->assertSee('Registration number')
            ->assertSee('Regular driver');

        $this->actingAs($this->manager())
            ->get(route('assets.create', ['category' => 'computer']))
            ->assertOk()
            ->assertDontSee('Registration number');
    }

    public function test_an_unknown_category_on_the_add_page_falls_back_to_the_picker(): void
    {
        $this->actingAs($this->manager())
            ->get(route('assets.create', ['category' => 'spaceship']))
            ->assertOk()
            ->assertSee('What kind of thing is it?');
    }

    public function test_the_summary_counts_what_is_waiting_to_be_capitalised(): void
    {
        $this->probe(['name' => 'Waiting one']);
        $this->capitalise($this->probe(['name' => 'In the books']));

        $this->actingAs($this->manager())
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('1 asset(s) carry a cost that is not in the books yet');
    }

    public function test_an_asset_can_be_moved_and_handed_over_and_the_history_says_so(): void
    {
        $asset = $this->probe(['name' => 'Printer', 'location' => 'Head office']);
        $other = $this->makeUser(['name' => 'New custodian']);

        $this->actingAs($this->manager())
            ->put(route('assets.update', $asset), [
                'location' => 'Chattogram depot',
                'custodian_id' => $other->id,
                'condition' => 'fair',
            ])
            ->assertRedirect();

        $asset->refresh();

        $this->assertSame('Chattogram depot', $asset->location);
        $this->assertSame($other->id, (int) $asset->custodian_id);
        $this->assertSame('fair', $asset->condition);

        $this->assertDatabaseHas('business_asset_events', [
            'business_asset_id' => $asset->id,
            'action' => 'moved',
        ]);
    }

    /* ------------------------------------------------------------------- menu */

    public function test_the_catalogue_leaves_land_on_the_asset_register(): void
    {
        $uris = [
            '/app/assets',
            '/app/assets/vehicles',
            '/app/assets/trips',
            '/app/assets/equipment',
            '/app/assets/create',
            '/app/assets/depreciation',
            '/app/assets/disposal',
        ];

        foreach ($uris as $uri) {
            $leaf = MenuItem::query()
                ->where('status', 'active')
                ->where('route', $uri)
                ->first();

            $this->assertNotNull($leaf, "the catalogue leaf for {$uri} is not in the navigation registry");
        }
    }
}
