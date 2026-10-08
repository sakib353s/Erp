<?php

namespace Tests\Feature;

use App\Domain\Business\Services\VisitorService;
use App\Domain\Business\Visitor;
use App\Domain\Business\VisitorVisit;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use Carbon\Carbon;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-16 — the visitor desk.
 *
 * What is pinned here, in the order the gate actually asks it:
 *
 *  · **a booking is not an arrival** — pre-registering writes down who is
 *    expected and issues no badge, records no time, and counts nobody as inside;
 *  · **admitting somebody is a fact with a time** — checking in issues the day's
 *    next badge, stamps the clock, and tells the host exactly once;
 *  · **the blacklist is the one thing a gate must not get wrong** — a refused
 *    person cannot be booked in or admitted, and nobody is written onto the list
 *    while they are still inside the building;
 *  · **the register keeps people apart from visits** — one record per person, one
 *    row per visit, which is what makes the reports lens worth reading;
 *  · **nothing is stored twice** — how long somebody stayed is arithmetic on the
 *    two timestamps, and a visit's state is its life cycle, not a clock reading;
 *  · **the three menu leaves point at real pages**, and the two permissions are
 *    real doors.
 */
class VisitorDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->headOffice = $this->defaultBranch();
        $this->bindTenantContext($this->admin, $this->headOffice);

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------- helpers */

    protected function gate(): VisitorService
    {
        return app(VisitorService::class);
    }

    /**
     * A phone number derived from the name, so two fixtures with different names
     * are two different people. The register matches a returning visitor on their
     * phone, which is exactly what one of the tests below pins down.
     */
    protected function phoneFor(string $name): string
    {
        return '017'.str_pad((string) (crc32($name) % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /** Somebody in front of the gate who has never been here before. */
    protected function person(string $name = 'Rakib Hasan', array $extra = []): Visitor
    {
        return $this->gate()->person(array_merge([
            'name' => $name,
            'phone' => $this->phoneFor($name),
            'organisation' => 'Nexus Couriers',
        ], $extra), $this->admin);
    }

    /** A booking made the way the desk makes one. */
    protected function book(string $name = 'Rakib Hasan', string $when = '2027-03-11 10:00', array $extra = []): VisitorVisit
    {
        return $this->gate()->preRegister(array_merge([
            'name' => $name,
            'phone' => $this->phoneFor($name),
            'organisation' => 'Nexus Couriers',
            'host_user_id' => $this->admin->id,
            'purpose' => 'meeting',
            'scheduled_for' => $when,
            'meet_at' => 'Reception, 3rd floor',
        ], $extra), $this->admin);
    }

    /** Somebody walking in right now. */
    protected function arrive(string $name = 'Salma Begum', array $extra = []): VisitorVisit
    {
        return $this->gate()->checkIn($this->admin, array_merge([
            'name' => $name,
            'phone' => '01911000002',
            'purpose' => 'delivery',
            'branch_id' => $this->headOffice->id,
        ], $extra));
    }

    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();

        $this->grant($user, $keys);

        return $user;
    }

    /* --------------------------------------------------------------- the log */

    public function test_the_desk_shows_who_is_inside_the_building_right_now(): void
    {
        $visit = $this->arrive('Salma Begum');

        $page = $this->actingAs($this->admin)->get(route('business.visitors.index'));

        $page->assertOk();
        $page->assertSee('Salma Begum');
        $page->assertSee($visit->badge_no);
        $page->assertSee('On the premises now');
        // The badge series starts the day's count at one.
        $this->assertSame('VB-20270310-01', $visit->badge_no);
    }

    public function test_the_badge_series_advances_once_per_arrival_per_day(): void
    {
        $first = $this->arrive('Salma Begum');
        $second = $this->arrive('Jashim Uddin', ['phone' => '01911000003', 'purpose' => 'vendor']);

        $this->assertSame('VB-20270310-01', $first->badge_no);
        $this->assertSame('VB-20270310-02', $second->badge_no);

        // Tomorrow's series starts again at one — a badge is a day's mark, not a
        // permanent number.
        Carbon::setTestNow(Carbon::parse('2027-03-11 09:00:00'));
        $tomorrow = $this->arrive('Nadia Islam', ['phone' => '01911000004']);

        $this->assertSame('VB-20270311-01', $tomorrow->badge_no);
    }

    /* ---------------------------------------------------------- the booking */

    public function test_a_booking_issues_no_badge_records_no_time_and_counts_nobody_as_inside(): void
    {
        $visit = $this->book('Rakib Hasan', '2027-03-11 10:00');

        $this->assertTrue($visit->isExpected());
        $this->assertNull($visit->badge_no);
        $this->assertNull($visit->checked_in_at);

        $summary = $this->gate()->summary();

        $this->assertSame(0, $summary['inside']);
        $this->assertSame(0, $summary['arrived_today'], 'A booking for tomorrow is not an arrival today.');
        $this->assertSame(0, $summary['expected_today'], 'It is expected tomorrow, not today.');
        $this->assertSame(1, $this->gate()->diary(7)['tomorrow']->count());

        // The host is not told about a booking — only about an arrival.
        $this->assertSame(0, Notification::query()->where('event_type', 'business.visitor.arrived')->count());
    }

    public function test_a_booking_for_another_day_cannot_be_checked_in_today(): void
    {
        $visit = $this->book('Rakib Hasan', '2027-03-12 10:00');

        $response = $this->actingAs($this->admin)
            ->from(route('business.visitors.show', $visit))
            ->post(route('business.visitors.check-in', $visit));

        $response->assertRedirect(route('business.visitors.show', $visit));
        $response->assertSessionHasErrors('badge_no');

        $this->assertStringContainsString('12 Mar 2027', $this->allFlashedErrors());
        $this->assertTrue($visit->refresh()->isExpected());
        $this->assertNull($visit->badge_no);
    }

    public function test_the_diary_shows_today_tomorrow_and_the_rest_of_the_week(): void
    {
        $today = $this->book('Today Person', '2027-03-10 15:00');
        $tomorrow = $this->book('Tomorrow Person', '2027-03-11 11:00');
        $later = $this->book('Later Person', '2027-03-14 11:00');

        $diary = $this->gate()->diary(7);

        $this->assertSame([$today->id], $diary['today']->pluck('id')->all());
        $this->assertSame([$tomorrow->id], $diary['tomorrow']->pluck('id')->all());
        $this->assertSame([$later->id], $diary['rest']->flatten()->pluck('id')->all());

        $page = $this->actingAs($this->admin)->get(route('business.visitors.expected'));
        $page->assertOk();
        $page->assertSee('Today Person');
        $page->assertSee('Tomorrow Person');

        // The diary is a booking lens: nobody in it is inside.
        $this->assertSame(0, $this->gate()->summary()['inside']);
    }

    public function test_a_booking_stops_at_the_month_horizon_and_never_starts_in_the_past(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gate keeps a diary');

        $this->book('Far Away', '2027-05-20 10:00');
    }

    public function test_a_booking_cannot_be_made_for_yesterday(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already passed');

        $this->book('Yesterday Person', '2027-03-09 10:00');
    }

    /* ------------------------------------------------------- checking in and out */

    public function test_checking_in_stamps_the_clock_issues_a_badge_and_tells_the_host_once(): void
    {
        $visit = $this->book('Rakib Hasan', '2027-03-10 14:00');

        $this->actingAs($this->admin)->post(route('business.visitors.check-in', $visit))->assertRedirect();

        $visit->refresh();

        $this->assertTrue($visit->isInside());
        $this->assertSame('VB-20270310-01', $visit->badge_no);
        $this->assertSame('2027-03-10 09:00', $visit->checked_in_at->format('Y-m-d H:i'));
        $this->assertSame(1, Notification::query()->where('event_type', 'business.visitor.arrived')->where('user_id', $this->admin->id)->count());

        // Checking in twice is refused, and the second attempt does not ring again.
        $response = $this->actingAs($this->admin)->post(route('business.visitors.check-in', $visit));
        $response->assertSessionHasErrors('badge_no');
        $this->assertSame(1, Notification::query()->where('event_type', 'business.visitor.arrived')->count());
    }

    public function test_checking_out_closes_the_visit_and_the_stay_is_the_difference_of_two_timestamps(): void
    {
        $visit = $this->arrive('Salma Begum');

        Carbon::setTestNow(Carbon::parse('2027-03-10 12:35:00'));

        $this->actingAs($this->admin)->post(route('business.visitors.check-out', $visit))->assertRedirect();

        $visit->refresh();

        $this->assertTrue($visit->isOut());
        $this->assertSame(215, $visit->dwellMinutes());
        $this->assertSame('3h 35m', $visit->dwellLabel());

        // A second check-out names the state it is actually in.
        $response = $this->actingAs($this->admin)->from(route('business.visitors.show', $visit))->post(route('business.visitors.check-out', $visit));
        $response->assertSessionHasErrors('note');
        $this->assertStringContainsString('already Checked out', $this->allFlashedErrors());
    }

    public function test_a_booking_nobody_arrived_for_can_be_closed_as_a_no_show_once_its_day_has_passed(): void
    {
        $visit = $this->book('Rakib Hasan', '2027-03-10 16:00');

        // Pulling the register forward is how the desk sees it the next morning.
        Carbon::setTestNow(Carbon::parse('2027-03-11 08:00:00'));

        // Not before the day is over.
        $this->assertTrue($visit->refresh()->isNoShowBy());

        $this->actingAs($this->admin)->post(route('business.visitors.no-show', $visit))->assertRedirect(route('business.visitors.expected'));

        $visit->refresh();
        $this->assertTrue($visit->isNoShow());
        $this->assertSame(1, $this->gate()->report()['no_shows']);
    }

    public function test_a_booking_can_be_cancelled_with_a_reason_and_a_visit_that_happened_cannot(): void
    {
        $cancelled = $this->book('Rakib Hasan', '2027-03-11 10:00');

        $this->actingAs($this->admin)
            ->post(route('business.visitors.cancel', $cancelled), ['cancel_reason' => 'The supplier rescheduled.'])
            ->assertRedirect(route('business.visitors.expected'));

        $this->assertTrue($cancelled->refresh()->isCancelled());
        $this->assertSame('The supplier rescheduled.', $cancelled->cancel_reason);

        // A visit that already happened is a fact, not a plan.
        $inside = $this->arrive('Salma Begum');
        $response = $this->actingAs($this->admin)->from(route('business.visitors.show', $inside))
            ->post(route('business.visitors.cancel', $inside), ['cancel_reason' => 'oops']);

        $response->assertSessionHasErrors('cancel_reason');
        $this->assertTrue($inside->refresh()->isInside());
    }

    /* ------------------------------------------------------------ the blacklist */

    public function test_a_blacklisted_person_is_refused_at_the_door_and_at_the_booking_form(): void
    {
        $visitor = $this->person('Rakib Hasan');
        $this->gate()->blacklist($visitor, $this->admin, 'Left with stock from the sample room.');

        // The door.
        $response = $this->actingAs($this->admin)
            ->from(route('business.visitors.walkin'))
            ->post(route('business.visitors.walkin.store'), [
                'visitor_id' => $visitor->id,
                'purpose' => 'meeting',
            ]);

        $response->assertSessionHasErrors('visitor_id');
        $this->assertStringContainsString('sample room', $this->allFlashedErrors());
        $this->assertSame(0, VisitorVisit::query()->count(), 'A refused visitor leaves no visit row.');

        // The booking form.
        $response = $this->actingAs($this->admin)
            ->from(route('business.visitors.create'))
            ->post(route('business.visitors.store'), [
                'visitor_id' => $visitor->id,
                'purpose' => 'meeting',
                'scheduled_for' => '2027-03-12 10:00',
            ]);

        $response->assertSessionHasErrors('visitor_id');
        $this->assertSame(0, VisitorVisit::query()->count());
    }

    public function test_nobody_is_put_on_the_blacklist_while_they_are_still_inside_the_building(): void
    {
        $visit = $this->arrive('Salma Begum');
        $visitor = $visit->visitor;

        $response = $this->actingAs($this->admin)
            ->from(route('business.visitors.people'))
            ->post(route('business.visitors.people.blacklist', $visitor), ['blacklist_reason' => 'Argument at the gate']);

        $response->assertSessionHasErrors('blacklist_reason');
        $this->assertStringContainsString('Check them out first', $this->allFlashedErrors());
        $this->assertFalse($visitor->refresh()->isBlacklisted());
    }

    public function test_the_blacklist_can_be_lifted_and_the_person_admitted_again(): void
    {
        $visitor = $this->person('Rakib Hasan');
        $this->gate()->blacklist($visitor, $this->admin, 'Delivered a parcel that was never signed for.');

        $this->actingAs($this->admin)
            ->post(route('business.visitors.people.restore', $visitor))
            ->assertRedirect(route('business.visitors.people'));

        $this->assertFalse($visitor->refresh()->isBlacklisted());

        // And the gate opens again.
        $visit = $this->gate()->checkIn($this->admin, ['visitor_id' => $visitor->id, 'purpose' => 'meeting']);
        $this->assertTrue($visit->isInside());
    }

    public function test_the_register_is_one_record_per_person_however_many_times_they_come(): void
    {
        $first = $this->arrive('Salma Begum', ['phone' => '01911000002']);
        $this->gate()->checkOut($first, $this->admin);

        // Same phone, spelled differently: the same person, not a second row.
        $second = $this->arrive('Salma Begum ', ['phone' => '01911000002']);

        $this->assertSame($first->visitor_id, $second->visitor_id);
        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame(2, Visitor::query()->firstOrFail()->visits()->count());

        $page = $this->actingAs($this->admin)->get(route('business.visitors.people'));
        $page->assertOk();
        $page->assertSee('Salma Begum');
    }

    public function test_the_register_masks_the_paper_a_visitor_showed(): void
    {
        $this->person('Rakib Hasan', ['id_type' => 'nid', 'id_number' => '1990123456789']);

        $page = $this->actingAs($this->admin)->get(route('business.visitors.people'));

        $page->assertOk();
        $page->assertSee('National ID');
        $page->assertSee('••••••6789');
        $page->assertDontSee('1990123456789');
    }

    /* ------------------------------------------------------- the reports lens */

    public function test_the_reports_lens_counts_the_same_rows_by_gate_host_and_purpose(): void
    {
        $this->arrive('Salma Begum', ['purpose' => 'delivery', 'host_user_id' => $this->admin->id]);
        $second = $this->arrive('Jashim Uddin', ['phone' => '01911000003', 'purpose' => 'vendor', 'host_user_id' => $this->admin->id]);

        Carbon::setTestNow(Carbon::parse('2027-03-10 10:30:00'));
        $this->gate()->checkOut($second, $this->admin);

        $report = $this->gate()->report();

        $this->assertSame(2, $report['visits']);
        $this->assertSame(2, $report['unique_people']);
        $this->assertSame(0, $report['returning_people']);
        $this->assertSame(2, $report['walk_ins']);
        $this->assertSame(0, $report['pre_booked']);
        $this->assertSame(90, $report['average_minutes']);
        $this->assertSame(1, $report['still_inside']);
        $this->assertSame('2027-03-10', $report['busiest_day']);
        $this->assertArrayHasKey($this->headOffice->name, $report['by_branch']);
        $this->assertArrayHasKey('Delivery / courier', $report['by_purpose']);
        $this->assertArrayHasKey('Vendor / supplier', $report['by_purpose']);
        $this->assertArrayHasKey($this->admin->name, $report['by_host']);

        $page = $this->actingAs($this->admin)->get(route('business.visitors.reports'));
        $page->assertOk();
        $page->assertSee('Average stay');
        $page->assertSee($this->headOffice->name);
    }

    /* --------------------------------------------------------------- the doors */

    public function test_the_desk_is_scoped_to_the_branches_a_reader_is_posted_to(): void
    {
        $other = Branch::query()->create([
            'company_id' => Company::current()?->id,
            'name' => 'Chattogram Gate',
            'code' => 'CTG',
        ]);

        $mine = $this->arrive('Salma Begum');
        $theirs = $this->arrive('Other Branch Visitor', [
            'phone' => '01911000009',
            'branch_id' => $other->id,
        ]);

        $reader = $this->makeUser(['branch_scope' => 'assigned']);
        $this->grant($reader, ['business.visitors.view']);

        $page = $this->actingAs($reader)->get(route('business.visitors.index'));

        $page->assertOk();
        $page->assertSee('Salma Begum');
        $page->assertDontSee('Other Branch Visitor');

        // And the other gate's visit does not exist for them, rather than existing
        // and being forbidden.
        $this->actingAs($reader)->get(route('business.visitors.show', $theirs))->assertForbidden();
        $this->actingAs($reader)->get(route('business.visitors.show', $mine))->assertOk();
    }

    public function test_a_visit_from_another_company_does_not_exist_here(): void
    {
        $otherCompany = (int) \Illuminate\Support\Facades\DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $person = Visitor::query()->create([
            'company_id' => $otherCompany,
            'name' => 'Outsider',
        ]);

        $foreign = VisitorVisit::query()->create([
            'company_id' => $otherCompany,
            'visitor_id' => $person->id,
            'purpose' => 'meeting',
            'status' => VisitorVisit::STATUS_EXPECTED,
            'scheduled_for' => '2027-03-10 10:00',
        ]);

        $this->actingAs($this->admin)->get(route('business.visitors.show', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->post(route('business.visitors.check-in', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->post(route('business.visitors.people.restore', $person), [])->assertNotFound();
    }

    public function test_reading_needs_the_view_key_and_admitting_needs_the_manage_key(): void
    {
        $visit = $this->arrive('Salma Begum');

        $reader = $this->userWith(['business.visitors.view']);

        $this->actingAs($reader)->get(route('business.visitors.index'))->assertOk();
        $this->actingAs($reader)->get(route('business.visitors.show', $visit))->assertOk();

        // Reading the log is not the same as running the gate: the form is
        // readable — that is what a front desk needs — but nothing it posts is.
        $this->actingAs($reader)->get(route('business.visitors.walkin'))->assertOk();
        $this->actingAs($reader)->post(route('business.visitors.walkin.store'), ['name' => 'Reader Walk-in', 'purpose' => 'meeting'])->assertForbidden();
        $this->actingAs($reader)->post(route('business.visitors.check-out', $visit))->assertForbidden();
        $this->actingAs($reader)->post(route('business.visitors.people.blacklist', $visit->visitor_id), ['blacklist_reason' => 'nope'])->assertForbidden();

        $stranger = $this->makeUser();

        $this->actingAs($stranger)->get(route('business.visitors.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('business.visitors.reports'))->assertForbidden();
    }

    public function test_the_three_catalogue_leaves_point_at_real_pages(): void
    {
        app(\App\Domain\Foundation\Services\CatalogImporter::class)->sync();

        $group = MenuItem::query()->where('label', 'Visitors')->firstOrFail();
        $leaves = $group->children()->get();

        $this->assertCount(3, $leaves, 'The Visitors group should have three leaves.');

        $expected = [
            'Visitor Log' => '/app/visitors',
            'Pre-Registration' => '/app/visitors/expected',
            'Visitor Reports' => '/app/visitors/reports',
        ];

        foreach ($leaves as $leaf) {
            $this->assertArrayHasKey($leaf->label, $expected, "Unexpected leaf: {$leaf->label}");
            $this->assertSame($expected[$leaf->label], $leaf->route, "{$leaf->label} points at the wrong page.");
            $this->assertTrue($leaf->is_active, "{$leaf->label} should be an active menu row.");

            $this->actingAs($this->admin)->get($leaf->route)->assertOk();
        }
    }

    /* ------------------------------------------------------------ the morning watch */

    public function test_the_morning_watch_reports_the_diary_and_any_row_left_open(): void
    {
        $this->book('Expected Today', '2027-03-10 15:00');

        $inside = $this->arrive('Yesterday Visitor');
        // Somebody who came in yesterday and never went home.
        VisitorVisit::query()->whereKey($inside->id)->update(['checked_in_at' => '2027-03-09 17:00']);

        $this->artisan('erp:business:visitor-watch')->assertSuccessful();

        $this->assertSame(1, Notification::query()->where('event_type', 'business.visitor.expected_today')->count());
        $this->assertSame(1, Notification::query()->where('event_type', 'business.visitor.open_stay')->count());

        // One digest per state per company per day.
        $this->artisan('erp:business:visitor-watch')->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('event_type', 'business.visitor.expected_today')->count());

        // And with nothing to say, it says nothing.
        VisitorVisit::query()->where('status', VisitorVisit::STATUS_INSIDE)->update(['status' => VisitorVisit::STATUS_OUT, 'checked_out_at' => '2027-03-09 18:00']);
        VisitorVisit::query()->where('status', VisitorVisit::STATUS_EXPECTED)->update(['status' => VisitorVisit::STATUS_CANCELLED]);
        $this->artisan('erp:business:visitor-watch')->assertSuccessful();
        $this->assertSame(2, Notification::query()->whereIn('event_type', ['business.visitor.expected_today', 'business.visitor.open_stay'])->count());
    }

    public function test_the_morning_watch_is_quiet_when_nobody_holds_the_key(): void
    {
        $this->book('Expected Today', '2027-03-10 15:00');

        // The command is a courier, not a register: with nobody holding the key it
        // delivers nothing and says so, and the diary still shows the booking.
        $this->artisan('erp:business:visitor-watch', ['--permission' => 'business.visitors.unheld'])->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(1, $this->gate()->diary(7)['today']->count());
    }
}
