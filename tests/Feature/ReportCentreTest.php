<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportRegistry;
use App\Domain\Reporting\ReportRun;
use App\Domain\Reporting\ScheduledReport;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §13-01…§13-12 — the report centre, the family hubs and the two pages that are
 * about reports rather than of them.
 *
 * What this pins, in the order the complaints would arrive:
 *  · a hub lists only reports the application really registers — the registry
 *    reads the router, and a catalogue entry with no route cannot appear, so a
 *    hub can never offer a door that 404s;
 *  · a report that exists but whose key the reader lacks is *listed*, with the
 *    key it needs, rather than hidden — a manager must be able to ask for a
 *    screen the company already has, and “the menu does not show it” is how
 *    people end up believing the system cannot do it;
 *  · each family has its own key, because reading the sales reports is not
 *    reading the payroll;
 *  · a family with nothing built says so in words on the page, and the reports
 *    that do exist on it are still listed beside that note;
 *  · the centre is read-only except the one form it carries, which writes a
 *    schedule through the service that owns the rule — and a definition
 *    belonging to another company is not found at all.
 */
class ReportCentreTest extends TestCase
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
        $this->seed(NavigationSeeder::class);
    }

    // --------------------------------------------------------------- helpers

    /** A reader holding exactly the given keys (the admin holds everything). */
    protected function reader(array $keys): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($keys)->id);

        return $user;
    }

    protected function definition(string $code = 'WEEKLY-AR', ?int $companyId = null): ReportDefinition
    {
        return ReportDefinition::query()->create([
            'company_id' => $companyId ?? $this->admin->company_id,
            'code' => $code,
            'name' => 'Weekly receivables ageing',
            'source' => array_key_first(\App\Domain\Reporting\CustomReportBuilder::SOURCES),
            'columns' => ['invoice_no', 'due_date', 'balance'],
            'filters' => ['branch_id' => null],
            'created_by' => $this->admin->id,
        ]);
    }

    // ------------------------------------------------------------ the router

    /**
     * The centre's own promise, tested directly: nothing it names may be a route
     * the application does not have, and nothing it names may be unguarded — a
     * report with no permission on its route would be readable by every user.
     */
    public function test_the_registry_names_no_route_the_application_lacks_and_leaves_none_unguarded(): void
    {
        $registry = app(ReportRegistry::class);

        $this->assertSame([], $registry->dangling(), 'A catalogue report naming a route that does not exist would be a dead link on a hub.');
        $this->assertGreaterThan(0, $registry->total());

        foreach ($registry->entries() as $entry) {
            $this->assertNotNull(
                $entry['permission'],
                sprintf('The route %s carries no permission middleware, so its hub row could not say what it needs.', $entry['route']),
            );
        }

        // A family the catalogue does not name is not a family.
        $this->assertNull($registry->family('nonsense'));
    }

    // ----------------------------------------------------------- the centre

    public function test_the_centre_lists_every_family_with_what_each_one_really_holds(): void
    {
        $registry = app(ReportRegistry::class);

        $response = $this->actingAs($this->admin)->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Every report this system can actually open');
        $response->assertSee('Sales reports');
        $response->assertSee('Finance reports');
        $response->assertSee('VAT &amp; tax reports', false);

        // Every family is listed, including the ones with nothing in them.
        foreach (array_keys(ReportRegistry::FAMILIES) as $slug) {
            $response->assertSee(route('reports.'.$slug), false);
        }

        // The count on the screen is the registry's own count, not a typed number.
        $response->assertSee((string) $registry->total());
        $this->assertSame($registry->total(), count($registry->entries()));
    }

    public function test_a_reader_without_a_family_key_sees_the_family_named_but_cannot_open_it(): void
    {
        $reader = $this->reader(['reports.view']);

        $this->actingAs($reader)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('needs reports.finance');

        $this->actingAs($reader)->get(route('reports.finance'))->assertForbidden();

        // The floor key opens the centre and nothing else.
        $this->actingAs($reader)->get(route('reports.sales'))->assertForbidden();
        $this->actingAs($reader)->get(route('reports.custom'))->assertForbidden();
        $this->actingAs($reader)->get(route('reports.scheduled'))->assertForbidden();
    }

    // -------------------------------------------------------------- the hubs

    public function test_a_family_hub_links_what_the_reader_may_open_and_names_what_they_may_not(): void
    {
        // The family key opens the hub; the report's own key opens the report —
        // two doors, deliberately, so a manager can read the sales reports
        // without being handed the payroll. This reader holds the sales report
        // key, so every sales report is a link to them …
        $reader = $this->reader(['reports.view', 'reports.sales', 'sales.reports.view']);

        $response = $this->actingAs($reader)->get(route('reports.sales'));

        $response->assertOk();

        // The reports are listed …
        $response->assertSee('Sales summary');
        $response->assertSee('Invoice ageing');

        // … the ones this reader may open are links …
        $response->assertSee(route('sales.reports.summary'), false);

        // … and the ones they may not — the drawer and the returns register are
        // other people's keys — are named with the key they need rather than
        // hidden, and are not links.
        $response->assertSee('Needs')
            ->assertSee('pos.cash_drawer')
            ->assertSee('returns.view')
            ->assertDontSee(route('pos.drawer'), false);
    }

    public function test_a_hub_says_what_is_missing_in_words_instead_of_showing_a_blank_card(): void
    {
        $response = $this->actingAs($this->admin)->get(route('reports.marketing'));

        $response->assertOk();

        // Nothing in the marketing family has a route, so the hub is honest about it.
        $response->assertSee('No reports in this family yet');
        $response->assertSee('What is not here yet, and why');
        $response->assertSee('The campaign engine itself', false);

        $this->assertSame([], app(ReportRegistry::class)->entries('marketing'));
    }

    public function test_a_report_that_lives_on_a_detail_page_is_offered_through_its_register(): void
    {
        $response = $this->actingAs($this->admin)->get(route('reports.customers'));

        $response->assertOk();

        // A ledger needs a customer, so the hub links the register and says so.
        $response->assertSee('Customer ledger');
        $response->assertSee('opened from the register');
        $response->assertSee(route('customers.index'), false);
    }

    // ---------------------------------------------------- custom + scheduled

    public function test_the_saved_reports_register_shows_definitions_runs_and_schedules(): void
    {
        $definition = $this->definition();

        ReportRun::query()->create([
            'company_id' => $this->admin->company_id,
            'report_definition_id' => $definition->id,
            'triggered_by' => $this->admin->id,
            'status' => 'completed',
            'row_count' => 42,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addSeconds(3),
        ]);

        ReportRun::query()->create([
            'company_id' => $this->admin->company_id,
            'report_definition_id' => $definition->id,
            'triggered_by' => $this->admin->id,
            'status' => 'failed',
            'row_count' => 0,
            'error' => 'The branch you asked for is not in your scope.',
            'started_at' => now(),
        ]);

        ScheduledReport::query()->create([
            'company_id' => $this->admin->company_id,
            'report_definition_id' => $definition->id,
            'name' => 'Weekly ageing to the manager',
            'frequency' => 'weekly',
            'next_run_at' => now()->addDay(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.custom'));

        $response->assertOk();
        $response->assertSee('Weekly receivables ageing');
        $response->assertSee($definition->code);
        $response->assertSee('42');
        $response->assertSee('The branch you asked for is not in your scope.');

        // A run that failed is recorded as failed — the register never reads as
        // though an empty report had been produced successfully.
        $response->assertSee('failed');
    }

    public function test_the_builder_is_only_offered_to_a_reader_who_can_open_it(): void
    {
        $this->definition();

        // The builder is a sales-reports screen (§02-120) and is behind that key,
        // so a reader holding only the report-centre key is told what it needs.
        $reader = $this->reader(['reports.view', 'reports.custom']);

        $this->actingAs($reader)->get(route('reports.custom'))
            ->assertOk()
            ->assertSee('needs sales.reports.view')
            ->assertDontSee('Open the builder');

        $this->actingAs($this->admin)->get(route('reports.custom'))
            ->assertOk()
            ->assertSee(route('sales.reports.custom'), false);
    }

    public function test_a_schedule_is_written_through_the_service_that_owns_the_rule(): void
    {
        $definition = $this->definition();

        $this->actingAs($this->admin)
            ->post(route('reports.scheduled.store'), [
                'definition_id' => $definition->id,
                'name' => 'Weekly ageing to the manager',
                'frequency' => 'weekly',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('scheduled_reports', [
            'company_id' => $this->admin->company_id,
            'report_definition_id' => $definition->id,
            'name' => 'Weekly ageing to the manager',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);

        // A schedule starts due, so the next run of the command produces it.
        $schedule = ScheduledReport::query()->latest('id')->firstOrFail();
        $this->assertTrue($schedule->next_run_at->lessThanOrEqualTo(now()));

        // Writing a schedule is an audited act, like every other standing
        // instruction in this system.
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'sales.report_scheduled')
            ->where('entity_type', 'scheduled_report')
            ->count());

        $this->actingAs($this->admin)->get(route('reports.scheduled'))
            ->assertOk()
            ->assertSee('Weekly ageing to the manager')
            ->assertSee('weekly');
    }

    public function test_a_schedule_cannot_reach_a_definition_from_another_company(): void
    {
        // This instance is single-tenant by design (companies.singleton is a
        // unique column), so a row for "another company" is written the only way
        // one could ever exist — past the model, with the guard released. The
        // point of the test is the scope on the query, not how the row got there.
        $otherId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Somebody Else Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $theirs = $this->definition('THEIRS', $otherId);

        $this->actingAs($this->admin)
            ->post(route('reports.scheduled.store'), [
                'definition_id' => $theirs->id,
                'name' => 'Not mine',
                'frequency' => 'daily',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('scheduled_reports', ['name' => 'Not mine']);
    }

    public function test_a_schedule_refuses_a_rhythm_and_a_name_the_service_would_not_take(): void
    {
        $definition = $this->definition();

        $this->actingAs($this->admin)
            ->post(route('reports.scheduled.store'), [
                'definition_id' => $definition->id,
                'name' => '',
                'frequency' => 'hourly',
            ])
            ->assertSessionHasErrors(['name', 'frequency']);

        $this->assertDatabaseCount('scheduled_reports', 0);
    }

    // ------------------------------------------------------------ read-only

    public function test_the_centre_and_its_hubs_are_read_only(): void
    {
        $this->actingAs($this->admin)->post(route('reports.index'))->assertStatus(405);
        $this->actingAs($this->admin)->post(route('reports.finance'))->assertStatus(405);
        $this->actingAs($this->admin)->post(route('reports.custom'))->assertStatus(405);
        // The one form the centre carries is the schedule writer, and its URI is
        // also a page — so GETting that URL is the page, by design. What must
        // hold is that the form's own route is a POST and nothing else.
        $store = app('router')->getRoutes()->getByName('reports.scheduled.store');

        $this->assertNotNull($store);
        $this->assertSame(['POST'], $store->methods(), 'the only write on the centre is the schedule form');
    }
}
