<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use App\Domain\Masters\SmsProvider;
use App\Domain\Marketing\CampaignRecipient;
use App\Domain\Marketing\MarketingCampaign;
use App\Domain\Marketing\MarketingRegistry;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
use Carbon\Carbon;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §11 — the marketing desk.
 *
 * The rules this file exists to pin down, in the order somebody would ask them:
 *
 *  · **nothing claims a delivery it did not make** — an SMS is `queued` only when
 *    the company has a real, configured provider; email only when a real mail
 *    driver is set; WhatsApp and push have no transport at all, so their messages
 *    are held and the desk says so. No test here ever expects `sent`, because
 *    nothing in this application writes it;
 *  · **an opt-out is honoured by the machine** — the dispatcher skips the contact,
 *    writes a row saying why, and hands nothing to a transport;
 *  · **the audience is expanded on the day** — the rule is stored, and a customer
 *    who bought inside the window is in while one who did not is out;
 *  · **cost is the message price frozen per recipient, and revenue is the invoices
 *    those recipients actually raised** inside their own attribution window —
 *    never an estimate, never a click;
 *  · **a launched campaign is history** — not editable, not launchable twice, and
 *    cancellable only with a reason.
 */
class MarketingDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function customer(string $name, ?string $phone = '01710000', ?string $email = null, array $overrides = []): Customer
    {
        static $seq = 0;
        $seq++;

        return Customer::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'MKC-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'phone' => $phone === null ? null : $phone.'0'.$seq,
            'email' => $email ?? 'customer'.$seq.'@example.test',
            'is_active' => true,
        ], $overrides));
    }

    /** An issued invoice — the only thing the audience rules and the report read. */
    protected function invoice(Customer $customer, string $date, float $total): void
    {
        DB::table('invoices')->insert([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'document_type_id' => DB::table('document_types')->value('id'),
            'invoice_no' => 'INV-'.$customer->id.'-'.substr(md5($date.$total.$customer->id), 0, 6),
            'status' => 'issued',
            'posting_state' => 'posted',
            'invoice_date' => $date,
            'grand_total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function campaign(array $overrides = []): MarketingCampaign
    {
        return app(\App\Domain\Marketing\Services\CampaignService::class)->save(array_merge([
            'channel' => MarketingCampaign::CHANNEL_SMS,
            'name' => 'Eid offer',
            'body' => 'Dear {customer}, {company} has an offer this {month}. Reply STOP to opt out.',
            'audience' => MarketingCampaign::AUDIENCE_ALL,
            'cost_per_message' => '0.35',
        ], $overrides), null, $this->admin);
    }

    protected function smsProvider(): SmsProvider
    {
        return SmsProvider::query()->where('company_id', $this->admin->company_id)->orderBy('id')->firstOrFail();
    }

    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();
        $this->grant($user, $keys);

        return $user->fresh();
    }

    /* ------------------------------------------------------------- the campaign */

    public function test_a_campaign_is_written_down_as_a_draft_and_sends_nothing(): void
    {
        $campaign = $this->campaign();

        $this->assertSame('MC-000001', $campaign->code);
        $this->assertTrue($campaign->isDraft());
        $this->assertSame(0, $campaign->recipients()->count());
        $this->assertSame(0, OutboxMessage::query()->whereNotNull('marketing_campaign_id')->count());

        $this->actingAs($this->admin)
            ->get(route('marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSeeText('This is still a draft');
    }

    public function test_launching_expands_the_audience_and_hands_every_message_to_the_outbox(): void
    {
        $first = $this->customer('Rubina Akter');
        $second = $this->customer('Karim Stores');

        $this->smsProvider()->forceFill(['config_status' => 'configured', 'is_active' => true])->save();

        $campaign = $this->campaign();
        $result = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);

        $this->assertSame(2, $result['counts']['recipients']);
        $this->assertSame(2, $result['counts']['queued']);
        $this->assertSame(0, $result['counts']['held']);

        $this->assertTrue($result['campaign']->isLaunched());
        $this->assertSame(2, $result['campaign']->recipients()->count());

        foreach ($result['campaign']->recipients as $recipient) {
            $this->assertSame('queued', $recipient->state());
            $this->assertSame('0.3500', $recipient->cost);
            $this->assertSame($this->smsProvider()->code, $recipient->message->provider_code);
        }

        $this->assertSame(2, OutboxMessage::query()->where('marketing_campaign_id', $campaign->id)->count());
        $this->assertContains($first->id, $result['campaign']->recipients->pluck('customer_id')->all());
        $this->assertContains($second->id, $result['campaign']->recipients->pluck('customer_id')->all());

        // The body was rendered for the person reading it.
        $this->assertStringContainsString('Dear Rubina Akter', (string) $result['campaign']->recipients->first()->message->body);
        $this->assertStringContainsString('Nile Fashions Ltd', (string) $result['campaign']->recipients->first()->message->body);
        $this->assertStringContainsString('March', (string) $result['campaign']->recipients->first()->message->body);
    }

    public function test_a_channel_with_no_transport_is_held_and_the_desk_says_so(): void
    {
        $this->customer('WhatsApp Customer');

        $campaign = $this->campaign([
            'channel' => MarketingCampaign::CHANNEL_WHATSAPP,
            'body' => 'Hello {customer} from {company}.',
        ]);

        $result = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);

        $this->assertSame(1, $result['counts']['held']);
        $this->assertSame(0, $result['counts']['queued']);

        $recipient = $result['campaign']->recipients->first();
        $this->assertSame('not_configured', $recipient->state());
        $this->assertSame('Held', $recipient->stateLabel());
        $this->assertNull($recipient->message->provider_code);

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());

        $this->actingAs($this->admin)
            ->get(route('marketing.deliveries', ['channel' => 'whatsapp']))
            ->assertOk()
            ->assertSeeText('Held');
    }

    public function test_an_email_campaign_is_held_on_the_log_driver_and_queued_on_a_real_one(): void
    {
        $this->customer('Email Customer');

        config(['mail.default' => 'log']);
        $campaign = $this->campaign([
            'channel' => MarketingCampaign::CHANNEL_EMAIL,
            'subject' => 'What is new at {company}',
            'body' => 'Dear {customer}, here is what is new.',
        ]);

        $held = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);
        $this->assertSame('not_configured', $held['campaign']->recipients->first()->state());

        config(['mail.default' => 'smtp']);
        $second = $this->campaign([
            'channel' => MarketingCampaign::CHANNEL_EMAIL,
            'name' => 'Newsletter',
            'subject' => 'Monthly newsletter',
            'body' => 'Dear {customer}, here is what is new.',
        ]);

        $queued = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($second, $this->admin);
        $this->assertSame('queued', $queued['campaign']->recipients->first()->state());
        $this->assertSame('smtp', $queued['campaign']->recipients->first()->message->provider_code);
        $this->assertSame('What is new at Nile Fashions Ltd', $held['campaign']->recipients->first()->message->subject);
        $this->assertSame('Monthly newsletter', $queued['campaign']->recipients->first()->message->subject);
    }

    public function test_an_email_campaign_needs_a_subject_and_other_channels_drop_it(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('subject');

        $this->campaign([
            'channel' => MarketingCampaign::CHANNEL_EMAIL,
            'subject' => null,
            'body' => 'Dear {customer}.',
        ]);
    }

    public function test_a_non_email_campaign_keeps_no_subject(): void
    {
        $campaign = $this->campaign(['subject' => 'ignored on SMS']);

        $this->assertNull($campaign->subject);
    }

    public function test_a_template_from_another_channel_is_refused(): void
    {
        MessageTemplate::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'EMAIL-ONLY',
            'channel' => 'email',
            'name' => 'Email only',
            'body' => 'Hello {customer}.',
            'is_active' => true,
        ]);

        $template = MessageTemplate::query()->where('code', 'EMAIL-ONLY')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('channel');

        $this->campaign(['template_id' => $template->id]);
    }

    public function test_a_launched_campaign_is_history_not_an_editable_draft(): void
    {
        $this->customer('History Customer');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign();
        $service->launch($campaign, $this->admin);

        try {
            $service->save(['channel' => 'sms', 'name' => 'Renamed', 'body' => 'x', 'audience' => 'all'], $campaign, $this->admin);
            $this->fail('A launched campaign should not be editable.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('history', $error->getMessage());
        }

        try {
            $service->launch($campaign, $this->admin);
            $this->fail('A launched campaign should not launch twice.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('twice', $error->getMessage());
        }

        // Still exactly one row per customer — a second launch would have doubled it.
        $this->assertSame(1, $campaign->recipients()->count());
    }

    public function test_cancelling_needs_a_reason_and_closes_the_campaign(): void
    {
        $campaign = $this->campaign();
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);

        try {
            $service->cancel($campaign, $this->admin, '   ');
            $this->fail('Cancelling without a reason should be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('why', $error->getMessage());
        }

        $cancelled = $service->cancel($campaign, $this->admin, 'The festival is over.');
        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame('The festival is over.', $cancelled->cancel_reason);

        try {
            $service->launch($cancelled, $this->admin);
            $this->fail('A cancelled campaign should not launch.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cancelled', $error->getMessage());
        }
    }

    /* --------------------------------------------------------------- the audience */

    public function test_the_recent_buyers_rule_reads_the_window_from_the_invoices(): void
    {
        $recent = $this->customer('Recent Buyer');
        $old = $this->customer('Old Buyer');

        $this->invoice($recent, '2027-02-20', 5000);   // inside 90 days
        $this->invoice($old, '2026-08-01', 5000);      // outside it

        $campaign = $this->campaign([
            'audience' => MarketingCampaign::AUDIENCE_RECENT,
            'audience_days' => 90,
        ]);

        $audience = app(\App\Domain\Marketing\Services\CampaignService::class)->audience($campaign);

        $this->assertSame([$recent->id], $audience->pluck('id')->all());
    }

    public function test_the_gone_quiet_rule_is_the_other_side_of_the_same_window(): void
    {
        $recent = $this->customer('Recent Buyer');
        $dormant = $this->customer('Dormant Buyer');
        $never = $this->customer('Never Bought');

        $this->invoice($recent, '2027-03-01', 1000);
        $this->invoice($dormant, '2026-05-01', 1000);

        $campaign = $this->campaign([
            'audience' => MarketingCampaign::AUDIENCE_DORMANT,
            'audience_days' => 90,
        ]);

        $ids = app(\App\Domain\Marketing\Services\CampaignService::class)->audience($campaign)->pluck('id')->all();

        $this->assertContains($dormant->id, $ids);
        $this->assertNotContains($recent->id, $ids);
        // A customer who has never bought has nothing to win back.
        $this->assertNotContains($never->id, $ids);
    }

    public function test_a_hand_picked_campaign_writes_to_the_customers_it_names(): void
    {
        $chosen = $this->customer('Chosen One');
        $this->customer('Left Out');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $campaign = $this->campaign(['audience' => MarketingCampaign::AUDIENCE_MANUAL]);

        $result = app(\App\Domain\Marketing\Services\CampaignService::class)
            ->launch($campaign, $this->admin, [$chosen->id]);

        $this->assertSame(1, $result['counts']['recipients']);
        $this->assertSame($chosen->id, $result['campaign']->recipients->first()->customer_id);
    }

    public function test_a_hand_picked_campaign_with_nobody_chosen_is_not_launched(): void
    {
        $this->customer('Somebody');

        $campaign = $this->campaign(['audience' => MarketingCampaign::AUDIENCE_MANUAL]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('matches nobody');

        app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);
    }

    public function test_a_blacklisted_customer_is_skipped_with_the_reason_on_the_row(): void
    {
        $blacklisted = $this->customer('Barred & Co', '01790000', null, ['is_blacklisted' => true, 'blacklist_reason' => 'Cheques bounced twice']);
        $fine = $this->customer('Fine & Co');

        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $campaign = $this->campaign();
        $result = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);

        $this->assertSame(2, $result['counts']['recipients']);
        $this->assertSame(1, $result['counts']['skipped_blacklisted']);

        $row = $result['campaign']->recipients->firstWhere('customer_id', $blacklisted->id);
        $this->assertSame(CampaignRecipient::SKIP_BLACKLISTED, $row->skip_reason);
        $this->assertNull($row->outbox_message_id);
        $this->assertSame(1, OutboxMessage::query()->where('marketing_campaign_id', $campaign->id)->count());
        $this->assertNotNull($result['campaign']->recipients->firstWhere('customer_id', $fine->id)->outbox_message_id);
    }

    public function test_a_customer_with_no_address_is_skipped_rather_than_failed(): void
    {
        $noPhone = $this->customer('No Phone', null);

        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $result = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($this->campaign(), $this->admin);

        $this->assertSame(1, $result['counts']['skipped_no_address']);
        $row = $result['campaign']->recipients->firstWhere('customer_id', $noPhone->id);
        $this->assertSame(CampaignRecipient::SKIP_NO_ADDRESS, $row->skip_reason);
        $this->assertSame(0, $result['counts']['failed'] ?? 0);
    }

    /* ---------------------------------------------------------------- opt-outs */

    public function test_the_opt_out_register_is_honoured_by_the_dispatcher(): void
    {
        $opted = $this->customer('Opted Out Customer', '01812345');
        $this->customer('Everybody Else');

        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $service->optOut('sms', $opted->fresh()->phone, $this->admin, 'They replied STOP.', 'reply', $opted);

        $result = $service->launch($this->campaign(), $this->admin);

        $this->assertSame(1, $result['counts']['skipped_opted_out']);
        $this->assertSame(1, $result['counts']['queued']);

        $row = $result['campaign']->recipients->firstWhere('customer_id', $opted->id);
        $this->assertSame(CampaignRecipient::SKIP_OPTOUT, $row->skip_reason);
        $this->assertSame('Opted out', $row->stateLabel());
        $this->assertNull($row->outbox_message_id);
        $this->assertStringContainsString('asked not to be written to', $row->skipLabel());
    }

    public function test_an_opt_out_on_every_channel_blocks_every_channel(): void
    {
        $customer = $this->customer('Global Stop', '01911223');

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $service->optOut('all', $customer->fresh()->phone, $this->admin, 'Stop everything.', 'reply');

        // “Stop everything” is exactly that — every channel we have.
        $this->assertTrue($service->isOptedOut('sms', $customer->fresh()->phone));
        $this->assertTrue($service->isOptedOut('whatsapp', $customer->fresh()->phone));
        $this->assertTrue($service->isOptedOut('push', $customer->fresh()->phone));

        // A single-channel opt-out stays on its channel: an SMS STOP is not a
        // promise about the email list.
        $narrow = $this->customer('SMS Only Stop', '01733344');
        $service->optOut('sms', $narrow->fresh()->phone, $this->admin, 'Stop the texts.', 'reply');

        $this->assertTrue($service->isOptedOut('sms', $narrow->fresh()->phone));
        $this->assertFalse($service->isOptedOut('whatsapp', $narrow->fresh()->phone));
    }

    public function test_recording_the_same_opt_out_twice_keeps_one_entry(): void
    {
        $customer = $this->customer('Twice Stop', '01555443');
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);

        $first = $service->optOut('sms', $customer->fresh()->phone, $this->admin, 'First time.', 'reply');
        $second = $service->optOut('sms', $customer->fresh()->phone, $this->admin, 'Second time.', 'reply');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Domain\Marketing\MarketingOptout::query()->count());

        // Lifting it is deliberate and recorded.
        $service->liftOptOut($first, $this->admin);
        $this->assertSame(0, \App\Domain\Marketing\MarketingOptout::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'marketing.optout_lifted')->count());
    }

    public function test_the_contacts_lens_states_every_reason_a_customer_cannot_be_reached(): void
    {
        $reachable = $this->customer('Reachable');
        $noPhone = $this->customer('No Phone', null);
        $barred = $this->customer('Barred', '01700000', null, ['is_blacklisted' => true]);
        $opted = $this->customer('Opted Out', '01888888');

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $service->optOut('sms', $opted->fresh()->phone, $this->admin, null, 'reply');

        $reach = $service->contacts('sms');

        $this->assertSame(1, $reach['reachable']);
        $this->assertSame(1, $reach['no_address']);
        $this->assertSame(1, $reach['blacklisted']);
        $this->assertSame(1, $reach['opted_out']);

        $this->actingAs($this->admin)
            ->get(route('marketing.contacts', ['channel' => 'sms']))
            ->assertOk()
            ->assertSeeText('Reachable')
            ->assertSeeText('They asked not to be written to by SMS.')
            ->assertSeeText('Blacklisted');
    }

    /* --------------------------------------------------------------- scheduling */

    public function test_a_scheduled_campaign_goes_out_through_the_dispatcher(): void
    {
        $this->customer('Expected Recipient');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign();

        $service->schedule($campaign, $this->admin, '2027-03-11 09:00:00');
        $this->assertTrue($campaign->refresh()->isScheduled());

        Carbon::setTestNow(Carbon::parse('2027-03-11 09:30:00'));

        $this->artisan('erp:marketing:dispatch --company='.$this->admin->company_id)
            ->expectsOutputToContain('MC-000001 went out')
            ->assertSuccessful();

        $campaign->refresh();
        $this->assertTrue($campaign->isLaunched());
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame(1, OutboxMessage::query()->where('marketing_campaign_id', $campaign->id)->count());

        // A scheduled run is nobody's decision, so the audit trail says system.
        $event = AuditEvent::query()->where('action', 'marketing.campaign_launched')->latest('id')->firstOrFail();
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function test_the_dispatcher_leaves_a_campaign_whose_audience_is_empty_on_the_clock(): void
    {
        $this->customer('Never Bought');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign([
            'audience' => MarketingCampaign::AUDIENCE_RECENT,
            'audience_days' => 30,
        ]);
        $service->schedule($campaign, $this->admin, '2027-03-11 09:00:00');

        Carbon::setTestNow(Carbon::parse('2027-03-11 09:30:00'));

        $this->artisan('erp:marketing:dispatch --company='.$this->admin->company_id)
            ->expectsOutputToContain('stayed scheduled')
            ->assertSuccessful();

        $campaign->refresh();
        $this->assertTrue($campaign->isScheduled());
        $this->assertSame(0, $campaign->recipients()->count());
        $this->assertSame(0, OutboxMessage::query()->where('marketing_campaign_id', $campaign->id)->count());
    }

    public function test_the_dispatcher_does_nothing_on_a_dry_run(): void
    {
        $this->customer('Dry Run Recipient');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign();
        $service->schedule($campaign, $this->admin, '2027-03-11 09:00:00');

        Carbon::setTestNow(Carbon::parse('2027-03-11 09:30:00'));

        $this->artisan('erp:marketing:dispatch --company='.$this->admin->company_id.' --dry-run')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertTrue($campaign->refresh()->isScheduled());
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function test_a_campaign_cannot_be_put_on_a_time_that_has_gone(): void
    {
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already passed');

        $service->schedule($campaign, $this->admin, '2027-03-09 09:00:00');
    }

    public function test_a_scheduled_campaign_can_be_taken_off_the_clock(): void
    {
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $campaign = $this->campaign();
        $service->schedule($campaign, $this->admin, '2027-03-11 09:00:00');

        $service->unschedule($campaign, $this->admin);

        $this->assertTrue($campaign->refresh()->isDraft());
        $this->assertNull($campaign->scheduled_at);
    }

    /* --------------------------------------------------------------- reporting */

    public function test_the_report_counts_only_what_the_recipients_bought_inside_their_window(): void
    {
        $written = $this->customer('Written To');
        $skipped = $this->customer('Skipped — no phone', null);

        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $result = app(\App\Domain\Marketing\Services\CampaignService::class)->launch($this->campaign(), $this->admin);
        $campaign = $result['campaign'];

        // Two rows: one written to, one skipped for want of a number.
        $this->assertSame(2, $result['counts']['recipients']);
        $this->assertSame(1, $result['counts']['skipped_no_address']);

        // Inside the seven-day window.
        $this->invoice($written, '2027-03-12', 4000);
        // After it — outside the window, so it is not the campaign's doing.
        $this->invoice($written, '2027-03-25', 9000);
        // In the window, but this customer was never written to — the campaign
        // must not be credited with a sale it did not ask for.
        $this->invoice($skipped, '2027-03-12', 7000);

        $performance = app(\App\Domain\Marketing\Services\CampaignService::class)->performance();
        $row = $performance['campaigns']->firstWhere(fn (array $row) => $row['campaign']->id === $campaign->id);

        $this->assertSame(2, $row['recipients']);
        $this->assertSame(1, $row['handed']);
        $this->assertEqualsWithDelta(0.35, (float) $row['cost'], 0.0001);
        $this->assertSame(4000.0, $row['revenue']);
        $this->assertSame(1, $row['orders']);

        $this->actingAs($this->admin)
            ->get(route('marketing.reports'))
            ->assertOk()
            ->assertSeeText('MC-000001')
            ->assertSeeText('4,000.00');
    }

    public function test_the_delivery_lens_reads_the_outbox_and_shows_what_a_transport_said(): void
    {
        $this->customer('Bounce Customer');
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $campaign = $this->campaign();
        app(\App\Domain\Marketing\Services\CampaignService::class)->launch($campaign, $this->admin);

        $message = OutboxMessage::query()->where('marketing_campaign_id', $campaign->id)->firstOrFail();

        // Only a transport can report a failure — and the provider's words are kept.
        $message->forceFill(['status' => 'failed', 'last_error' => 'Operator rejected the sender id'])->save();

        $this->actingAs($this->admin)
            ->get(route('marketing.deliveries', ['channel' => 'sms', 'state' => 'failed']))
            ->assertOk()
            ->assertSeeText('Operator rejected the sender id')
            ->assertSeeText('Bounce Customer');
    }

    public function test_the_overview_counts_the_rows_and_nothing_else(): void
    {
        $this->customer('One');
        $this->customer('Two', null);
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();

        $service = app(\App\Domain\Marketing\Services\CampaignService::class);
        $service->launch($this->campaign(), $this->admin);
        $service->cancel($this->campaign(['name' => 'Cancelled one', 'audience' => MarketingCampaign::AUDIENCE_MANUAL]), $this->admin, 'Not needed.');

        $overview = $service->overview();

        $this->assertSame(2, $overview['campaigns']);
        $this->assertSame(1, $overview['launched']);
        $this->assertSame(0, $overview['drafts']);
        $this->assertSame(0, $overview['scheduled']);
        $this->assertSame(2, $overview['recipients']);
        $this->assertSame(1, $overview['queued']);
        $this->assertSame(1, $overview['skipped_no_address']);
        $this->assertEqualsWithDelta(0.35, (float) $overview['cost'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('marketing.campaigns.index'))
            ->assertOk()
            ->assertSeeText('MC-000001');
    }

    /* ------------------------------------------------------------- templates */

    public function test_the_starter_templates_are_written_once_and_never_over_an_edited_body(): void
    {
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);

        $created = $service->materialiseTemplates();
        $this->assertSame(count(MarketingRegistry::TEMPLATES), $created);

        $this->assertSame(0, $service->materialiseTemplates(), 'A second visit must not write them again.');

        $edited = MessageTemplate::query()->where('company_id', $this->admin->company_id)->firstOrFail();
        $edited->forceFill(['body' => 'Our own words {customer}.'])->save();

        $service->saveTemplate([
            'code' => 'MKT-OWN',
            'channel' => 'sms',
            'name' => 'Our own body',
            'body' => 'Something else {customer}.',
            'is_active' => true,
        ], null, $this->admin);

        $service->materialiseTemplates();

        $this->assertSame('Our own words {customer}.', $edited->refresh()->body);
        $this->assertSame(count(MarketingRegistry::TEMPLATES) + 1, MessageTemplate::query()->where('company_id', $this->admin->company_id)->count());
    }

    public function test_a_duplicate_template_code_on_the_same_channel_is_refused(): void
    {
        $service = app(\App\Domain\Marketing\Services\CampaignService::class);

        $service->saveTemplate(['code' => 'MKT-A', 'channel' => 'sms', 'name' => 'A', 'body' => 'Hello {customer}.', 'is_active' => true], null, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $service->saveTemplate(['code' => 'MKT-A', 'channel' => 'sms', 'name' => 'A again', 'body' => 'Hello again.', 'is_active' => true], null, $this->admin);
    }

    /* ------------------------------------------------------------------- doors */

    public function test_the_desk_asks_for_the_permission_and_audits_the_refusal(): void
    {
        $reader = $this->userWith(['marketing.campaigns.view']);
        $outsider = $this->userWith(['customers.view']);

        $this->actingAs($reader)
            ->get(route('marketing.campaigns.index'))
            ->assertOk();

        $this->actingAs($reader)
            ->get(route('marketing.campaigns.create'))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('marketing.campaigns.index'))
            ->assertForbidden();

        $this->assertSame(2, AuditEvent::query()->where('action', 'permission.denied')->count());
    }

    public function test_another_company_campaign_is_not_found_here(): void
    {
        // A second company is not "the instance" — the singleton flag is guarded
        // and has to be set around the mass assignment.
        $other = new Company(['name' => 'Somewhere Else', 'is_active' => true]);
        $other->forceFill(['singleton' => 0])->save();
        $elsewhere = $other;

        $foreign = MarketingCampaign::query()->create([
            'company_id' => $elsewhere->id,
            'code' => 'MC-000001',
            'channel' => 'sms',
            'name' => 'Their campaign',
            'body' => 'Hello.',
            'audience' => 'all',
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->get(route('marketing.campaigns.show', $foreign))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('marketing.optouts.destroy', \App\Domain\Marketing\MarketingOptout::query()->create([
                'company_id' => $elsewhere->id,
                'channel' => 'sms',
                'contact' => '01799999999',
                'source' => 'manual',
            ])))
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------- the menu */

    public function test_the_catalogue_points_the_marketing_leaves_at_real_pages(): void
    {
        app(\App\Domain\Foundation\Services\CatalogImporter::class)->sync();

        // The four channel groups are one desk; the SMS group is checked leaf by
        // leaf, because a leaf that points at a filter nobody implemented is a
        // link that quietly shows the wrong thing.
        $group = MenuItem::query()->where('label', 'SMS Marketing')->firstOrFail();
        $leaves = $group->children()->get();

        $this->assertCount(12, $leaves, 'The SMS Marketing group should have twelve leaves.');

        $expected = [
            'All SMS Campaigns' => '/app/marketing/campaigns?channel=sms',
            'Create SMS Campaign' => '/app/marketing/campaigns/create?channel=sms',
            'Broadcast SMS' => '/app/marketing/campaigns?channel=sms&audience=all',
            'Triggered SMS' => '/app/marketing/capabilities/triggered-messages',
            'Scheduled SMS' => '/app/marketing/campaigns?channel=sms&status=scheduled',
            'SMS Templates' => '/app/marketing/templates?channel=sms',
            'SMS Contacts' => '/app/marketing/contacts?channel=sms',
            'SMS Delivery Reports' => '/app/marketing/deliveries?channel=sms',
            'SMS Cost Reports' => '/app/marketing/reports?channel=sms',
            'SMS Opt-Out Management' => '/app/marketing/optouts?channel=sms',
            'SMS Balance' => '/app/marketing/capabilities/sms-balance',
            'SMS Settings' => '/app/settings/notifications',
        ];

        foreach ($leaves as $leaf) {
            $this->assertArrayHasKey($leaf->label, $expected, "Unexpected leaf: {$leaf->label}");
            $this->assertSame($expected[$leaf->label], $leaf->route, "{$leaf->label} points at the wrong page.");
            $this->assertTrue($leaf->is_active, "{$leaf->label} should be an active menu row.");

            $this->actingAs($this->admin)->get($leaf->route)->assertOk();
        }

        // The three groups this build does not model say so on every leaf, and do
        // not pretend: no invented pipeline, no affiliate balance, no reach figure.
        foreach (['Leads', 'Affiliates', 'Influencers'] as $groupLabel) {
            $group = MenuItem::query()
                ->where('label', $groupLabel)
                ->where('route', 'like', '/app/marketing/%')
                ->firstOrFail();

            $this->assertStringContainsString('/app/marketing/capabilities/', (string) $group->route, "{$groupLabel} should open the page that says what is missing.");

            foreach ($group->children()->get() as $leaf) {
                $this->assertStringContainsString('/app/marketing/capabilities/', (string) $leaf->route, "{$leaf->label} should open the page that says what is missing.");
                $this->actingAs($this->admin)->get($leaf->route)->assertOk();
            }
        }

        // Every other §11 leaf that was repinned likewise answers — including the
        // ones that deliberately open a capability page rather than a fake screen.
        $remining = MenuItem::query()
            ->whereNotNull('route')
            ->get()
            ->filter(fn (MenuItem $item) => str_contains((string) $item->route, '/app/marketing/'));

        $this->assertGreaterThan(60, $remining->count(), 'The §11 leaves should have been repinned.');

        foreach ($remining as $leaf) {
            $this->actingAs($this->admin)->get($leaf->route)->assertOk();
        }
    }

    public function test_a_capability_page_says_what_is_not_built_instead_of_pretending(): void
    {
        $this->actingAs($this->admin)
            ->get(route('marketing.capability', 'meta-platform'))
            ->assertOk()
            ->assertSeeText('does not talk to Meta')
            ->assertSeeText('No tracking pixel is emitted');

        $this->actingAs($this->admin)
            ->get(route('marketing.capability', 'not-a-topic'))
            ->assertNotFound();
    }

    public function test_the_win_back_preset_opens_the_gone_quiet_audience(): void
    {
        $this->actingAs($this->admin)
            ->get(route('marketing.campaigns.index', ['preset' => 'win-back']))
            ->assertOk()
            ->assertSeeText('Win-back campaigns')
            ->assertSeeText('Gone quiet');
    }

    public function test_a_campaign_can_be_written_and_launched_from_the_desk(): void
    {
        $this->smsProvider()->forceFill(['config_status' => 'configured'])->save();
        $this->customer('Desk Customer');

        $this->actingAs($this->admin)
            ->post(route('marketing.campaigns.store'), [
                'channel' => 'sms',
                'name' => 'Written at the desk',
                'body' => 'Hello {customer}, this is {company}.',
                'audience' => 'all',
                'cost_per_message' => '0.30',
                'attribution_days' => 5,
            ])
            ->assertRedirect();

        $campaign = MarketingCampaign::query()->where('name', 'Written at the desk')->firstOrFail();
        $this->assertTrue($campaign->isDraft());

        $this->actingAs($this->admin)
            ->post(route('marketing.campaigns.launch', $campaign))
            ->assertRedirect()
            ->assertSessionHas('status');

        $campaign->refresh();
        $this->assertTrue($campaign->isLaunched());
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertStringContainsString('1 queued', (string) session('status'));
    }

    public function test_the_desk_refuses_a_campaign_with_no_message(): void
    {
        $this->actingAs($this->admin)
            ->from(route('marketing.campaigns.create'))
            ->post(route('marketing.campaigns.store'), [
                'channel' => 'sms',
                'name' => 'Empty one',
                'body' => '',
                'audience' => 'all',
            ])
            ->assertSessionHasErrors('body');
    }
}
