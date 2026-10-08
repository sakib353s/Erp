<?php

namespace Tests\Feature;

use App\Domain\Business\BusinessRecord;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-03/04/09/10 — the company's registers and its compliance calendar.
 *
 * What is pinned, in the order the business would care about it:
 *
 *  · a record carries what makes it checkable — a number, an issuer, a value, and
 *    the date it stops being true; the kinds that cannot be recorded half-filled
 *    refuse rather than storing something nobody can act on;
 *  · the state of a record is read from the clock, not stored: the same row reads
 *    “expiring” today and “expired” next month without anybody touching it, and
 *    there is no `state` column to fall out of date;
 *  · a renewal extends the expiry and keeps the previous date on the record's own
 *    history — and may never move the expiry backwards, because tidying a screen
 *    is not a reason to rewrite what a register says;
 *  · a recurring duty, once done, sets its own next date from its deadline (so a
 *    late filing does not drag the calendar behind it) and steps over any cycles
 *    missed entirely;
 *  · evidence is attached, never copied: the paper stays in the document library;
 *  · retiring is a decision with a reason, and it does not delete anything;
 *  · the register is scoped to the branches a person can see, and writing to it
 *    is a different permission from reading it.
 */
class BusinessRecordTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* --------------------------------------------------------------- helpers */

    protected function recordsReader(User $user): void
    {
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'business.records.view'])->id);
    }

    protected function recordsManager(User $user): void
    {
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'business.records.view', 'business.records.manage'])->id);
    }

    protected function anotherBranch(string $code = 'DHN'): Branch
    {
        return Branch::query()->create([
            'company_id' => Company::current()->id,
            'code' => $code,
            'name' => 'Dhanmondi outlet',
        ]);
    }

    /** A document in the library, written straight in — the upload pipeline has its own suite. */
    protected function libraryFile(string $name = 'trade-licence-2026.pdf', ?int $companyId = null): Document
    {
        return Document::query()->create([
            'company_id' => $companyId ?? Company::current()->id,
            'branch_id' => $this->headOffice->id,
            'purpose' => 'attachment',
            'disk' => 'local',
            'path' => 'documents/'.uniqid('scan', true).'.pdf',
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 20480,
            'checksum' => hash('sha256', $name),
            'uploaded_by' => $this->admin->id,
        ]);
    }

    /**
     * Record something through the real screen.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function record(array $overrides = []): BusinessRecord
    {
        $payload = array_merge([
            'kind' => 'licence',
            'title' => 'Trade licence — Dhanmondi outlet',
            'reference_no' => 'TRAD/DHN/2026/118',
            'issuer' => 'Dhaka South City Corporation',
            'value_amount' => '6000',
            'issued_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->addDays(200)->toDateString(),
        ], $overrides);

        $this->actingAs($this->admin)->post(route('records.store'), $payload)->assertRedirect();

        return BusinessRecord::query()->latest('id')->firstOrFail();
    }

    /* ----------------------------------------------------------------- write */

    public function test_a_licence_is_recorded_with_its_number_issuer_and_expiry(): void
    {
        $record = $this->record();

        $this->assertSame('licence', $record->kind);
        $this->assertSame('TRAD/DHN/2026/118', $record->reference_no);
        $this->assertSame('Dhaka South City Corporation', $record->issuer);
        $this->assertSame('6000.00', $record->value_amount);
        $this->assertNull($record->branch_id, 'no branch given means company-wide');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.record_created',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
        ]);
        $this->assertDatabaseHas('business_record_events', [
            'business_record_id' => $record->id,
            'action' => 'created',
        ]);

        $this->actingAs($this->admin)
            ->get(route('records.kind', 'licence'))
            ->assertOk()
            ->assertSee('TRAD/DHN/2026/118')
            ->assertSee('Dhaka South City Corporation');

        $this->actingAs($this->admin)
            ->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('Trade licence — Dhanmondi outlet');
    }

    public function test_recording_a_licence_without_an_expiry_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('records.store'), [
            'kind' => 'licence',
            'title' => 'Trade licence with no end date',
            'expires_on' => '',
        ])->assertSessionHasErrors('expires_on');

        $this->assertSame(0, BusinessRecord::query()->count());
    }

    public function test_a_contract_needs_the_other_party(): void
    {
        $this->actingAs($this->admin)->post(route('records.store'), [
            'kind' => 'contract',
            'title' => 'Supply agreement for 2027',
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('counterparty');

        $this->assertSame(0, BusinessRecord::query()->count());

        $contract = $this->record([
            'kind' => 'contract',
            'title' => 'Supply agreement for 2027',
            'reference_no' => 'CT/2027/01',
            'counterparty' => 'Meghna Traders',
            'issuer' => null,
            'issued_on' => null,
            'value_amount' => '250000',
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
        ]);

        $this->assertSame('Meghna Traders', $contract->counterparty);
    }

    public function test_an_expiry_before_the_issue_date_is_refused_rather_than_stored(): void
    {
        $this->actingAs($this->admin)->post(route('records.store'), [
            'kind' => 'licence',
            'title' => 'Impossible licence',
            'issued_on' => now()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('expires_on');

        $this->assertSame(0, BusinessRecord::query()->count());
    }

    /* ------------------------------------------------------------- the clock */

    public function test_the_state_of_a_record_is_read_from_the_clock_and_never_stored(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');

        $record = $this->record([
            'issued_on' => '2026-01-05',
            'expires_on' => '2026-10-24',
        ]);

        $this->assertSame('expiring', $record->state());
        $this->assertSame(10, $record->daysLeft());

        // Nothing about the state lives in the database: it is a statement about
        // today, and there is no nightly job that has to be right for it to be true.
        $this->assertFalse(Schema::hasColumn('business_records', 'state'));

        Carbon::setTestNow('2026-11-01 09:00:00');

        $this->assertSame('expired', $record->fresh()->state());
        $this->assertSame('Expired', $record->fresh()->stateLabel());
        $this->assertSame(-8, $record->fresh()->daysLeft());

        // A record with no term at all says so instead of pretending to be valid.
        $tin = $this->record([
            'kind' => 'tax_id',
            'title' => 'TIN certificate',
            'reference_no' => 'TIN 452 118 907',
            'issuer' => 'National Board of Revenue',
            'value_amount' => null,
            'issued_on' => '2024-03-01',
            'expires_on' => null,
        ]);

        $this->assertSame('undated', $tin->state());
        $this->assertNull($tin->daysLeft());
    }

    /* --------------------------------------------------------------- renewal */

    public function test_a_renewal_moves_the_expiry_and_keeps_the_previous_one_on_the_history(): void
    {
        $previous = now()->addDays(10)->toDateString();
        $record = $this->record(['expires_on' => $previous]);

        $newExpiry = now()->addYear()->toDateString();

        $this->actingAs($this->admin)->post(route('records.renew', $record), [
            'expires_on' => $newExpiry,
            'renewed_on' => now()->toDateString(),
            'note' => 'Paid at the counter, receipt 4412',
        ])->assertRedirect();

        $fresh = $record->fresh();

        $this->assertSame($newExpiry, $fresh->expires_on->toDateString());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.record_renewed',
            'entity_id' => $record->id,
        ]);

        $event = $fresh->events()->where('action', 'renewed')->first();

        $this->assertNotNull($event);
        $this->assertSame($previous, $event->meta['previous_expires_on']);
        $this->assertSame($newExpiry, $event->meta['expires_on']);
        $this->assertSame('Paid at the counter, receipt 4412', $event->meta['note']);
    }

    public function test_a_renewal_cannot_move_the_expiry_backwards(): void
    {
        $expiry = now()->addDays(10)->toDateString();
        $record = $this->record(['expires_on' => $expiry]);

        $this->actingAs($this->admin)->post(route('records.renew', $record), [
            'expires_on' => now()->subDays(5)->toDateString(),
            'note' => 'Tidying the screen',
        ])->assertSessionHasErrors('expires_on');

        $this->assertSame($expiry, $record->fresh()->expires_on->toDateString());
        $this->assertDatabaseMissing('business_record_events', [
            'business_record_id' => $record->id,
            'action' => 'renewed',
        ]);
    }

    public function test_a_renewal_is_refused_for_a_record_that_has_no_term(): void
    {
        $asset = $this->record([
            'kind' => 'brand_asset',
            'title' => 'Master logo',
            'reference_no' => 'BRAND/LOGO/v3',
            'issuer' => null,
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
        ]);

        $this->actingAs($this->admin)->post(route('records.renew', $asset), [
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('expires_on');

        $this->assertNull($asset->fresh()->expires_on);
    }

    /* ------------------------------------------------------------ completion */

    public function test_a_filing_is_completed_and_sets_its_own_next_date(): void
    {
        $due = now()->addDays(20)->startOfDay();

        $filing = $this->record([
            'kind' => 'filing',
            'title' => 'Annual return to the registrar',
            'reference_no' => 'RJSC/AR/2026',
            'issuer' => 'RJSC',
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
            'due_on' => $due->toDateString(),
            'repeat_months' => 12,
        ]);

        $this->assertSame('due_soon', $filing->state());

        $this->actingAs($this->admin)->post(route('records.complete', $filing), [
            'completed_on' => now()->toDateString(),
            'note' => 'Filed online, acknowledgement 7712',
        ])->assertRedirect();

        $fresh = $filing->fresh();

        $this->assertSame(now()->toDateString(), $fresh->last_completed_on->toDateString());
        $this->assertSame(
            $due->copy()->addMonthsNoOverflow(12)->toDateString(),
            $fresh->due_on->toDateString(),
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.record_completed',
            'entity_id' => $filing->id,
        ]);
        $this->assertDatabaseHas('business_record_events', [
            'business_record_id' => $filing->id,
            'action' => 'completed',
        ]);
    }

    public function test_a_late_filing_rolls_forward_from_the_deadline_not_from_the_day_it_was_done(): void
    {
        $due = now()->subDays(15)->startOfDay();

        $return = $this->record([
            'kind' => 'obligation',
            'title' => 'Monthly VAT return (Mushak 9.1)',
            'reference_no' => null,
            'issuer' => 'National Board of Revenue',
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
            'due_on' => $due->toDateString(),
            'repeat_months' => 1,
        ]);

        $this->assertSame('overdue', $return->state());

        $this->actingAs($this->admin)->post(route('records.complete', $return), [
            'completed_on' => now()->toDateString(),
        ])->assertRedirect();

        $next = $return->fresh()->due_on;

        // Same day next month, not “a month after we finally did it”.
        $this->assertSame($due->copy()->addMonthsNoOverflow(1)->toDateString(), $next->toDateString());
        $this->assertTrue($next->isFuture());
    }

    public function test_completing_something_that_does_not_repeat_is_refused_with_a_sentence(): void
    {
        $record = $this->record();

        $this->actingAs($this->admin)->post(route('records.complete', $record), [
            'completed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('due_on');

        $this->assertNull($record->fresh()->last_completed_on);
        $this->assertDatabaseMissing('business_record_events', [
            'business_record_id' => $record->id,
            'action' => 'completed',
        ]);
    }

    /* --------------------------------------------------------------- evidence */

    public function test_a_paper_from_the_library_is_filed_against_a_record_and_unfiled_again(): void
    {
        $record = $this->record();
        $document = $this->libraryFile();

        $this->actingAs($this->admin)->post(route('records.attach', $record), [
            'document_id' => $document->id,
            'label' => '2026 renewal scan',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_record_files', [
            'business_record_id' => $record->id,
            'document_id' => $document->id,
            'label' => '2026 renewal scan',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.record_document_attached',
            'entity_id' => $record->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('records.show', $record))
            ->assertOk()
            ->assertSee('2026 renewal scan')
            ->assertSee('trade-licence-2026.pdf');

        $this->actingAs($this->admin)
            ->delete(route('records.detach', [$record, $document]))
            ->assertRedirect();

        $this->assertDatabaseMissing('business_record_files', [
            'business_record_id' => $record->id,
            'document_id' => $document->id,
        ]);

        // Unfiling removes the filing, never the file.
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_the_same_paper_cannot_be_filed_twice_against_one_record(): void
    {
        $record = $this->record();
        $document = $this->libraryFile();

        $this->actingAs($this->admin)->post(route('records.attach', $record), ['document_id' => $document->id]);
        $this->actingAs($this->admin)->post(route('records.attach', $record), ['document_id' => $document->id]);

        $this->assertSame(1, $record->files()->count());
        $this->assertSame(1, $record->events()->where('action', 'attached')->count());
    }

    public function test_filing_a_paper_that_is_not_in_the_library_is_refused(): void
    {
        $record = $this->record();

        $this->actingAs($this->admin)->post(route('records.attach', $record), [
            'document_id' => 99999,
        ])->assertSessionHasErrors('document_id');

        $this->assertSame(0, $record->files()->count());
    }

    /* --------------------------------------------------------------- retiring */

    public function test_retiring_a_record_needs_a_reason_and_leaves_the_row_in_place(): void
    {
        $record = $this->record(['title' => 'Trade licence 2025 (superseded)']);

        $this->actingAs($this->admin)->post(route('records.retire', $record), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $record->fresh()->status);

        $this->actingAs($this->admin)->post(route('records.retire', $record), [
            'reason' => 'Superseded by the 2027 licence',
        ])->assertRedirect();

        $fresh = $record->fresh();

        $this->assertSame('retired', $fresh->status);
        $this->assertSame(now()->toDateString(), $fresh->retired_on->toDateString());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.record_retired',
            'entity_id' => $record->id,
        ]);

        // Off the working list, still on the register.
        $this->assertDatabaseHas('business_records', ['id' => $record->id]);

        $this->actingAs($this->admin)
            ->get(route('records.kind', 'licence'))
            ->assertOk()
            ->assertDontSee('Trade licence 2025 (superseded)');

        $this->actingAs($this->admin)
            ->get(route('records.kind', ['licence', 'state' => 'retired']))
            ->assertOk()
            ->assertSee('Trade licence 2025 (superseded)');
    }

    /* ------------------------------------------------------------------ scope */

    public function test_a_record_from_another_branch_is_not_visible_to_a_branch_scoped_person(): void
    {
        $other = $this->anotherBranch();

        $mine = $this->record(['branch_id' => $this->headOffice->id, 'title' => 'Licence — head office']);
        $theirs = $this->record(['branch_id' => $other->id, 'title' => 'Licence — Dhanmondi']);
        $companyWide = $this->record(['branch_id' => null, 'title' => 'Certificate of incorporation']);

        $keeper = $this->makeUser(['branch_scope' => 'assigned', 'default_branch_id' => $this->headOffice->id]);
        $this->recordsReader($keeper);

        $this->actingAs($keeper)->get(route('records.show', $mine))->assertOk();
        $this->actingAs($keeper)->get(route('records.show', $companyWide))->assertOk();
        $this->actingAs($keeper)->get(route('records.show', $theirs))->assertNotFound();

        $this->actingAs($keeper)
            ->get(route('records.index'))
            ->assertOk()
            ->assertSee('Licence — head office')
            ->assertSee('Certificate of incorporation')
            ->assertDontSee('Licence — Dhanmondi');
    }

    /* ----------------------------------------------------------------- lenses */

    public function test_the_renewals_lens_lists_lapsed_before_soon_before_later(): void
    {
        $lapsed = $this->record([
            'title' => 'Fire safety licence (lapsed)',
            'issued_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->subDays(9)->toDateString(),
        ]);
        $soon = $this->record([
            'title' => 'Stock insurance policy',
            'kind' => 'insurance',
            'issuer' => 'Green Delta',
            'issued_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->addDays(12)->toDateString(),
        ]);
        $later = $this->record([
            'title' => 'Trade licence — renewal due later',
            'issued_on' => now()->toDateString(),
            'expires_on' => now()->addDays(70)->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('compliance.renewals'))->assertOk();

        $response->assertSeeInOrder(['Already lapsed', $lapsed->title, 'Inside the next 30 days', $soon->title]);

        $body = $response->getContent();
        $this->assertStringContainsString($later->title, $body);
        // The 70-day record is in the later section, and not in the lapsed one:
        // the sections are ordered by how soon somebody has to act.
        $this->assertGreaterThan(strpos($body, $lapsed->title), strpos($body, $later->title));
    }

    public function test_the_calendar_places_a_deadline_on_its_own_day(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');

        $this->record([
            'kind' => 'obligation',
            'title' => 'Quarterly TDS deposit',
            'reference_no' => null,
            'issuer' => 'National Board of Revenue',
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
            'due_on' => '2026-10-22',
            'repeat_months' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('compliance.calendar', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertSee('Quarterly TDS deposit');

        // A month that is not a month falls back to this one instead of erroring.
        $this->actingAs($this->admin)
            ->get(route('compliance.calendar', ['month' => 'not-a-month']))
            ->assertOk()
            ->assertSee('October 2026');
    }

    public function test_the_recurring_duties_are_grouped_by_how_often_they_come_round(): void
    {
        $this->record([
            'kind' => 'obligation',
            'title' => 'Monthly VAT return (Mushak 9.1)',
            'reference_no' => null,
            'issuer' => 'National Board of Revenue',
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
            'due_on' => now()->addDays(6)->toDateString(),
            'repeat_months' => 1,
        ]);
        $this->record([
            'kind' => 'filing',
            'title' => 'Annual return to the registrar',
            'reference_no' => null,
            'issuer' => 'RJSC',
            'value_amount' => null,
            'issued_on' => null,
            'expires_on' => null,
            'due_on' => now()->addDays(40)->toDateString(),
            'repeat_months' => 12,
        ]);

        $this->actingAs($this->admin)
            ->get(route('compliance.obligations'))
            ->assertOk()
            ->assertSeeInOrder(['Monthly', 'Monthly VAT return (Mushak 9.1)', 'Yearly', 'Annual return to the registrar']);
    }

    /* ------------------------------------------------------------- permission */

    public function test_a_reader_may_read_the_registers_but_not_write_to_them(): void
    {
        $record = $this->record();
        $reader = $this->makeUser();
        $this->recordsReader($reader);

        $this->actingAs($reader)->get(route('records.index'))->assertOk();
        $this->actingAs($reader)->get(route('records.kind', 'contract'))->assertOk();
        $this->actingAs($reader)->get(route('records.show', $record))->assertOk();
        $this->actingAs($reader)->get(route('compliance.renewals'))->assertOk();
        $this->actingAs($reader)->get(route('compliance.calendar'))->assertOk();

        // No create form for a reader, and every write is refused.
        $this->actingAs($reader)
            ->get(route('records.kind', 'licence'))
            ->assertOk()
            ->assertDontSee('Record a trade licence');

        $this->actingAs($reader)->post(route('records.store'), [
            'kind' => 'licence',
            'title' => 'Should not exist',
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($reader)->post(route('records.renew', $record), [
            'expires_on' => now()->addYear()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($reader)->post(route('records.retire', $record), ['reason' => 'Nope'])->assertForbidden();

        // And somebody with no key at all cannot even open the desk.
        $stranger = $this->makeUser();
        $this->actingAs($stranger)->get(route('records.index'))->assertForbidden();
    }

    /* -------------------------------------------------------------- the watch */

    public function test_the_daily_watch_tells_the_register_keepers_what_has_lapsed_and_what_is_close(): void
    {
        $keeper = $this->makeUser();
        $this->recordsManager($keeper);

        $lapsed = $this->record([
            'title' => 'Fire safety licence (lapsed)',
            'issued_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->subDays(9)->toDateString(),
        ]);
        $soon = $this->record([
            'kind' => 'insurance',
            'title' => 'Stock insurance policy',
            'issuer' => 'Green Delta',
            'issued_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->addDays(12)->toDateString(),
        ]);

        $this->artisan('erp:business:compliance-alerts')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $keeper->id,
            'event_type' => 'business.compliance.lapsed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $keeper->id,
            'event_type' => 'business.compliance.expiring',
        ]);

        // The digest names what it is about, so nobody has to open the register to find out.
        $lapsedBody = (string) \App\Domain\Notification\Notification::query()
            ->where('user_id', $keeper->id)
            ->where('event_type', 'business.compliance.lapsed')
            ->value('body');
        $this->assertStringContainsString($lapsed->title, $lapsedBody);

        $soonBody = (string) \App\Domain\Notification\Notification::query()
            ->where('user_id', $keeper->id)
            ->where('event_type', 'business.compliance.expiring')
            ->value('body');
        $this->assertStringContainsString($soon->title, $soonBody);

        // Running it again the same morning adds nothing: it is a digest, not a bell.
        $before = \App\Domain\Notification\Notification::query()->count();
        $this->artisan('erp:business:compliance-alerts')->assertSuccessful();
        $this->assertSame($before, \App\Domain\Notification\Notification::query()->count());
    }

    public function test_the_watch_says_so_when_nobody_holds_the_key(): void
    {
        $this->record([
            'title' => 'Fire safety licence (lapsed)',
            'issued_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->subDays(9)->toDateString(),
        ]);

        $this->artisan('erp:business:compliance-alerts', ['--permission' => 'nobody.holds.this'])
            ->expectsOutputToContain('Nobody holds nobody.holds.this')
            ->assertSuccessful();

        $this->assertSame(0, \App\Domain\Notification\Notification::query()
            ->where('event_type', 'like', 'business.compliance%')
            ->count());
    }

    /* ------------------------------------------------------------------- menu */

    public function test_the_catalogue_leaves_land_on_the_registers_they_name(): void
    {
        $expected = [
            '/app/records/licence',
            '/app/records/tax-id',
            '/app/records/certificate',
            '/app/records/contract',
            '/app/records/agreement',
            '/app/records/brand-asset',
            '/app/records/insurance',
            '/app/records/filing',
            '/app/compliance/renewals',
            '/app/compliance/calendar',
            '/app/compliance/obligations',
        ];

        foreach ($expected as $route) {
            $leaf = MenuItem::query()
                ->where('status', 'active')
                ->where('route', 'like', $route.'%')
                ->first();

            $this->assertNotNull($leaf, "the catalogue leaf for {$route} is not in the navigation registry");
        }
    }
}
