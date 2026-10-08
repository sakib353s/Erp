<?php

namespace Tests\Feature;

use App\Domain\Business\Notice;
use App\Domain\Business\NoticeAcknowledgement;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-12 — the notice board.
 *
 * What is pinned, in the order a company would care about it:
 *
 *  · an audience is honoured — a notice addressed to one branch is not readable
 *    by another by typing its web address, and its notifications reach exactly
 *    the people it named;
 *  · an acknowledgement is a row against a person, written once, and it is still
 *    there after a new request, a new login and a cleared inbox — which is the
 *    whole reason it is not a `read_at` on a notification;
 *  · publishing twice does not notify everybody twice (the dedupe key is stable);
 *  · writing, reading and acknowledging are three different permissions, and a
 *    reader who is not a publisher cannot publish;
 *  · the ledger keeps the names it was published to, including anybody who has
 *    since moved out of the audience.
 */
class NoticeBoardTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->headOffice = $this->defaultBranch();
    }

    /* --------------------------------------------------------------- helpers */

    protected function reader(User $user): void
    {
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'business.notices.view'])->id);
    }

    protected function publisher(User $user): void
    {
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'business.notices.view', 'business.notices.create'])->id);
    }

    /**
     * One notice, published through the real screen.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publishNotice(array $overrides = []): Notice
    {
        $payload = array_merge([
            'title' => 'Office closed on 21 February',
            'body' => "For Shaheed Dibosh the office is closed.\nCounter staff on rota are informed separately.",
            'category' => 'general',
            'audience_type' => Notice::AUDIENCE_ALL,
            'intent' => 'publish',
        ], $overrides);

        $this->actingAs($this->admin)->post(route('notices.store'), $payload)->assertRedirect();

        return Notice::query()->latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------------ write */

    public function test_a_publisher_writes_and_publishes_a_notice(): void
    {
        $notice = $this->publishNotice();

        $this->assertSame('published', $notice->status);
        $this->assertNotNull($notice->published_at);
        $this->assertSame($this->admin->id, $notice->created_by);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.notice_created',
            'entity_type' => 'notice',
            'entity_id' => $notice->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.notice_published',
            'entity_id' => $notice->id,
        ]);
    }

    public function test_a_draft_is_not_published_and_says_so(): void
    {
        $this->actingAs($this->admin)->post(route('notices.store'), [
            'title' => 'Half-written thought',
            'body' => 'Not ready.',
            'category' => 'general',
            'audience_type' => Notice::AUDIENCE_ALL,
            'intent' => 'draft',
        ])->assertRedirect();

        $notice = Notice::query()->latest('id')->firstOrFail();

        $this->assertSame('draft', $notice->status);
        $this->assertNull($notice->published_at);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_a_reader_may_not_write_or_publish(): void
    {
        $reader = $this->makeUser();
        $this->reader($reader);

        $this->actingAs($reader)->get(route('notices.create'))->assertForbidden();

        $notice = $this->publishNotice();

        $this->actingAs($reader)->post(route('notices.publish', $notice))->assertForbidden();
        $this->actingAs($reader)->get(route('notices.register'))->assertForbidden();
        $this->actingAs($reader)->get(route('notices.tracking'))->assertForbidden();
    }

    public function test_an_audience_that_names_nobody_is_refused_rather_than_sent_to_nobody(): void
    {
        $this->actingAs($this->admin)->post(route('notices.store'), [
            'title' => 'Sent to no one',
            'body' => 'This would reach nobody.',
            'category' => 'policy',
            'audience_type' => Notice::AUDIENCE_ROLES,
            'intent' => 'publish',
        ])->assertSessionHasErrors('audience_roles');

        $this->assertSame(0, Notice::query()->count());
    }

    /* --------------------------------------------------------------- audience */

    public function test_a_notice_reaches_its_audience_and_nobody_else(): void
    {
        $included = $this->makeUser(['name' => 'Included Person']);
        $excluded = $this->makeUser(['name' => 'Excluded Person']);

        $this->reader($included);
        $this->reader($excluded);

        $notice = $this->publishNotice([
            'audience_type' => Notice::AUDIENCE_USERS,
            'audience_users' => [$included->id],
        ]);

        // Exactly one person was told, and it is the person it was addressed to.
        $this->assertSame(1, Notification::query()->where('event_type', 'notice.published')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $included->id, 'title' => $notice->title]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $excluded->id]);

        // The board shows it to the included reader and not to the other.
        $this->actingAs($included)->get(route('notices.index'))->assertOk()->assertSee($notice->title);
        $this->actingAs($excluded)->get(route('notices.index'))->assertOk()->assertDontSee($notice->title);

        // And the URL is not a way around the audience.
        $this->actingAs($excluded)->get(route('notices.show', $notice))->assertForbidden();
        $this->actingAs($included)->get(route('notices.show', $notice))->assertOk();
    }

    public function test_a_draft_is_visible_only_to_the_people_who_may_publish(): void
    {
        $reader = $this->makeUser();
        $this->reader($reader);

        $this->actingAs($this->admin)->post(route('notices.store'), [
            'title' => 'Still a draft',
            'body' => 'Nobody else may read this yet.',
            'category' => 'hr',
            'audience_type' => Notice::AUDIENCE_ALL,
            'intent' => 'draft',
        ])->assertRedirect();

        $notice = Notice::query()->latest('id')->firstOrFail();

        $this->actingAs($reader)->get(route('notices.show', $notice))->assertNotFound();
        $this->actingAs($this->admin)->get(route('notices.show', $notice))->assertOk()->assertSee('Still a draft');
    }

    public function test_publishing_twice_does_not_notify_everybody_twice(): void
    {
        $person = $this->makeUser();
        $this->reader($person);

        $notice = $this->publishNotice();

        $this->assertSame(1, Notification::query()->where('user_id', $person->id)->count());

        // Publishing an amended notice again updates the board rather than
        // putting a second copy in every inbox.
        $this->actingAs($this->admin)->post(route('notices.publish', $notice))->assertRedirect();

        $this->assertSame(1, Notification::query()->where('user_id', $person->id)->count());
    }

    /* -------------------------------------------------------- acknowledgement */

    public function test_an_acknowledgement_is_persisted_and_survives_a_new_session(): void
    {
        $person = $this->makeUser();
        $this->reader($person);

        $notice = $this->publishNotice(['requires_acknowledgement' => '1']);

        $this->assertTrue((bool) $notice->requires_acknowledgement);

        $this->actingAs($person)
            ->post(route('notices.acknowledge', $notice), ['note' => 'Read and understood'])
            ->assertRedirect();

        $row = NoticeAcknowledgement::query()->where('notice_id', $notice->id)->where('user_id', $person->id)->firstOrFail();
        $this->assertSame('Read and understood', $row->note);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.notice_acknowledged',
            'entity_id' => $notice->id,
        ]);

        // A fresh request — as after a refresh, a new login or an emptied inbox.
        $this->actingAs($person)
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertSee('You acknowledged this notice');

        // And it is not on the waiting list any more.
        $this->assertSame([], $this->actingAs($person)->get(route('notices.index'))->viewData('awaiting'));
    }

    public function test_acknowledging_twice_keeps_the_first_record(): void
    {
        $person = $this->makeUser();
        $this->reader($person);

        $notice = $this->publishNotice(['requires_acknowledgement' => '1']);

        $this->actingAs($person)->post(route('notices.acknowledge', $notice))->assertRedirect();
        $first = NoticeAcknowledgement::query()->where('notice_id', $notice->id)->firstOrFail();

        $this->actingAs($person)->post(route('notices.acknowledge', $notice))->assertRedirect();

        $this->assertSame(1, NoticeAcknowledgement::query()->where('notice_id', $notice->id)->count());
        $this->assertSame($first->id, NoticeAcknowledgement::query()->where('notice_id', $notice->id)->firstOrFail()->id);
    }

    public function test_a_notice_that_asks_for_nothing_records_nothing(): void
    {
        $person = $this->makeUser();
        $this->reader($person);

        $notice = $this->publishNotice();

        $this->actingAs($person)
            ->post(route('notices.acknowledge', $notice))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'does not ask for acknowledgement'));

        $this->assertSame(0, NoticeAcknowledgement::query()->count());
    }

    public function test_the_ledger_counts_the_audience_and_keeps_the_names_it_was_published_to(): void
    {
        $stayer = $this->makeUser(['name' => 'Stayed Reader']);
        $leaver = $this->makeUser(['name' => 'Moved Away']);

        $this->reader($stayer);
        $this->reader($leaver);

        $notice = $this->publishNotice(['requires_acknowledgement' => '1']);

        $this->actingAs($stayer)->post(route('notices.acknowledge', $notice))->assertRedirect();
        $this->actingAs($leaver)->post(route('notices.acknowledge', $notice))->assertRedirect();

        $ledger = app(\App\Domain\Business\Services\NoticeService::class)->ledger($notice);
        $this->assertSame(2, $ledger['acknowledged']);
        $this->assertSame(0, $ledger['pending']);
        $this->assertEquals(100.0, $ledger['percent']);

        // The leaver stops being active: they are not audience any more, but
        // their acknowledgement does not vanish from the record.
        $leaver->forceFill(['status' => 'suspended'])->save();

        $after = app(\App\Domain\Business\Services\NoticeService::class)->ledger($notice);

        $this->assertSame(1, $after['audience']);
        $this->assertSame(1, $after['acknowledged']);
        $this->assertCount(1, $after['extra']);
        $this->assertSame($leaver->id, $after['extra']->first()->user_id);
    }

    public function test_the_tracking_page_shows_every_live_notice_that_asks_for_one(): void
    {
        $person = $this->makeUser();
        $this->reader($person);

        $asking = $this->publishNotice(['title' => 'Read the safety policy', 'requires_acknowledgement' => '1']);
        $this->publishNotice(['title' => 'Canteen menu', 'body' => 'Nothing to sign.']);

        $this->actingAs($this->admin)
            ->get(route('notices.tracking'))
            ->assertOk()
            ->assertSee('Read the safety policy')
            ->assertDontSee('Canteen menu')
            ->assertSee('1')
            ->assertSee('0%');

        $this->actingAs($person)->post(route('notices.acknowledge', $asking))->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('notices.tracking'))
            ->assertOk()
            ->assertSee('100%');
    }

    /* ------------------------------------------------------------------ menu */

    public function test_the_catalogue_leaf_the_board_was_promised_lands_on_the_board(): void
    {
        $leaf = \App\Domain\Foundation\MenuItem::query()
            ->where('status', 'active')
            ->where('route', 'like', '/app/notices%')
            ->first();

        $this->assertNotNull($leaf, 'the notice board leaf is not in the navigation registry');
        $this->assertStringContainsString('notices', $leaf->route);
    }
}
