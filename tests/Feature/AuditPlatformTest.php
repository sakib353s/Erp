<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditArchive;
use App\Domain\Audit\AuditVocabulary;
use App\Domain\Audit\Services\AuditChainVerifier;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
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
 * §16-33 / §16-34 / §16-35 — the audit platform: its vocabulary, its seals, and
 * the doors that read it.
 *
 * These three rows had code before they had tests: the recorder wrote a chain,
 * the verifier could walk it, and the viewer could list it — but nothing pinned
 * what happens when a row is edited, when a company-wide row belongs to another
 * company, when an old row is *deleted*, or whether a filtered page is showing
 * everything it claims to show. So this file starts from the failures that
 * matter to an audit trail:
 *
 *  · **every recorded action is a known word** — checked by reading the source
 *    tree, not by trusting a list;
 *  · **the viewer is scoped twice** — company first, then branches, and a
 *    company-wide row of somebody else's company is not \"mine\";
 *  · **tampering is named** — the row hash, not a vague \"something changed\";
 *  · **a deleted row is caught by the seal**, which is the only thing that can
 *    catch it, because the chain describes rows that are present;
 *  · **verifying leaves a mark** — a check that writes nothing cannot be told
 *    apart from a page nobody ever checked.
 */
class AuditPlatformTest extends TestCase
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

    /** @param array<int, string> $keys */
    protected function userWith(array $keys, array $attributes = []): User
    {
        $user = $this->makeUser($attributes);
        $this->grant($user, $keys);

        return $user->fresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function event(string $action, array $attributes = []): AuditEvent
    {
        return app(AuditRecorder::class)->record($attributes + [
            'action' => $action,
            'entity_type' => 'test',
            'entity_id' => 1,
        ]);
    }

    /* ------------------------------------------------------------- §16-33 */

    public function test_every_action_recorded_in_the_source_tree_is_a_known_word(): void
    {
        $unknown = [];
        $actions = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/'action'\\s*=>\\s*'([A-Za-z0-9_.\\-]+)'/", $source, $literal);
            preg_match_all("/recordModel\\(\\s*'([A-Za-z0-9_.\\-]+)'/", $source, $model);
            preg_match_all("/record\\(\\s*'([A-Za-z0-9_.\\-]+)'/", $source, $direct);

            foreach (array_merge($literal[1], $model[1], $direct[1]) as $action) {
                // Two hits are request payloads, not audit actions: a validation
                // rule named `action` and the workflow request that carries it.
                if (in_array($action, ['action', 'create'], true)) {
                    continue;
                }

                $actions[$action] = true;

                if (! AuditVocabulary::knows($action)) {
                    $unknown[] = $action.' ('.str_replace(base_path().'/', '', $file).')';
                }
            }
        }

        $this->assertGreaterThan(200, count($actions), 'The scan should find the whole recorded vocabulary.');
        $this->assertSame([], array_values(array_unique($unknown)), 'Actions were recorded that the vocabulary does not know.');

        foreach (array_keys($actions) as $action) {
            $this->assertSame(strtolower($action), $action, "Action [{$action}] is not lower case.");
        }
    }

    public function test_the_older_names_still_mean_what_they_meant(): void
    {
        $this->assertSame('sales.cod_reconciled', AuditVocabulary::canonical('reconcile'));
        $this->assertSame('sales.commission_paid', AuditVocabulary::canonical('pay'));
        $this->assertSame('workflow.escalated', AuditVocabulary::canonical('escalate'));
        $this->assertSame('sales.bulk_printed', AuditVocabulary::canonical('print_invoice'));
        $this->assertSame('sales.bulk_notified', AuditVocabulary::canonical('notify_sms'));

        $this->assertSame('Sales', AuditVocabulary::moduleLabel('reconcile'));
        $this->assertSame('Commission paid', AuditVocabulary::label('pay'));
        $this->assertSame('Invoice issued', AuditVocabulary::label('sales.invoice_issued'));
        // An explicit null branch is a company-wide row, and stays one.
        $wide = $this->event('config.protected_denied', ['branch_id' => null, 'reason' => 'company wide']);
        $this->assertNull($wide->fresh()->branch_id);
        $this->assertFalse(AuditVocabulary::isSensitive('sales.invoice_issued'));

        $this->assertTrue(AuditVocabulary::isSensitive('auth.login_failed'));
        $this->assertTrue(AuditVocabulary::isSensitive('documents.public_link_revoked'));
    }

    public function test_a_filter_finds_the_rows_recorded_under_the_older_name(): void
    {
        $this->event('reconcile', ['reason' => 'Cairo run, cash counted']);
        $this->event('sales.cod_reconciled', ['reason' => 'Gulshan run, cash counted']);

        $this->actingAs($this->admin)
            ->get(route('audit.index', ['action' => 'sales.cod_reconciled']))
            ->assertOk()
            ->assertSeeText('Cairo run')
            ->assertSeeText('Gulshan run');

        // …and the module filter finds them both as well, because the module is
        // what a person is actually looking under.
        $this->actingAs($this->admin)
            ->get(route('audit.index', ['module' => 'sales']))
            ->assertOk()
            ->assertSeeText('Cairo run')
            ->assertSeeText('Gulshan run');

        $this->actingAs($this->admin)
            ->get(route('audit.index', ['module' => 'inventory']))
            ->assertOk()
            ->assertDontSeeText('Cairo run');
    }

    /* ------------------------------------------------------------- §16-35 */

    public function test_the_viewer_never_shows_another_companys_row_even_when_the_actor_matches(): void
    {
        $elsewhere = new Company(['name' => 'Other Traders Ltd', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        // The old shape of the branch filter ended in an ungrouped orWhere, so a
        // company-wide row of *this* actor matched whatever company it belonged
        // to. The row below is exactly that trap.
        DB::table('audit_events')->insert([
            'company_id' => $elsewhere->id,
            'seq' => (int) DB::table('audit_events')->where('company_id', $elsewhere->id)->max('seq') + 1,
            'action' => 'sales.invoice_issued',
            'actor_type' => 'user',
            'actor_id' => $this->admin->id,
            'actor_label' => $this->admin->name,
            'branch_id' => null,
            'result' => 'success',
            'reason' => 'another company entirely',
            'prev_hash' => null,
            'row_hash' => str_repeat('a', 64),
            'created_at' => now(),
        ]);

        $this->event('sales.invoice_issued', ['reason' => 'ours']);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText('ours')
            ->assertDontSeeText('another company entirely');
    }

    public function test_a_branch_limited_person_sees_their_branch_and_their_own_company_wide_rows(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Chattogram depot',
            'code' => 'CTG',
            'is_default' => false,
            'is_active' => true,
        ]);

        // `makeUser` assigns the default branch when the scope is `assigned`.
        $restricted = $this->userWith(['audit.view'], ['branch_scope' => 'assigned']);

        $this->event('sales.invoice_issued', ['branch_id' => $this->defaultBranch()->id, 'reason' => 'my branch']);
        $this->event('sales.invoice_issued', ['branch_id' => $other->id, 'reason' => 'their branch']);
        $this->event('sales.invoice_issued', ['branch_id' => null, 'reason' => 'company wide by somebody else']);

        // …and one company-wide row recorded *by* this person, which they may read
        // even though it belongs to no branch.
        app(AuditRecorder::class)->record([
            'action' => 'sales.invoice_issued',
            'branch_id' => null,
            'actor' => $restricted,
            'reason' => 'company wide by me',
        ]);

        $response = $this->actingAs($restricted)->get(route('audit.index'))->assertOk();

        $response->assertSeeText('my branch');
        $response->assertSeeText('company wide by me');
        $response->assertDontSeeText('their branch');
        $response->assertDontSeeText('company wide by somebody else');
    }

    public function test_an_event_of_another_company_is_not_readable_by_id(): void
    {
        $elsewhere = new Company(['name' => 'Other Traders Ltd', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        $foreignId = DB::table('audit_events')->insertGetId([
            'company_id' => $elsewhere->id,
            'seq' => (int) DB::table('audit_events')->where('company_id', $elsewhere->id)->max('seq') + 1,
            'action' => 'sales.invoice_issued',
            'actor_type' => 'system',
            'branch_id' => null,
            'result' => 'success',
            'prev_hash' => null,
            'row_hash' => str_repeat('b', 64),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('audit.show', ['event' => $foreignId]))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------- §16-34 */

    public function test_the_chain_verifies_and_names_the_row_that_was_edited(): void
    {
        $first = $this->event('sales.invoice_issued', ['reason' => 'first']);
        $second = $this->event('sales.invoice_issued', ['reason' => 'second']);
        $this->event('purchase.order_approved', ['reason' => 'third']);

        $verifier = app(AuditChainVerifier::class);

        $this->assertTrue($verifier->verify((int) $this->admin->company_id)['ok']);

        // Somebody edits an old row — the failure the chain exists to catch.
        DB::table('audit_events')->where('id', $second->id)->update(['reason' => 'quietly changed']);

        $result = $verifier->verify((int) $this->admin->company_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('row_hash_mismatch', $result['reason']);
        $this->assertSame((int) $second->seq, (int) $result['broken_at']);
        $this->assertSame((int) $second->seq - 1, $result['checked'], 'The rows before the edit still verify.');

        $this->assertNotNull($first->fresh()->row_hash);
    }

    public function test_the_viewer_says_the_chain_is_broken_and_verifying_leaves_a_mark(): void
    {
        $event = $this->event('sales.invoice_issued', ['reason' => 'untouched']);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText('Chain verified');

        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'sales.invoice_void']);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText('Chain broken')
            ->assertSeeText('row_hash_mismatch');

        $this->actingAs($this->admin)
            ->post(route('audit.verify'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $verification = AuditEvent::query()->where('action', 'security.chain_verified')->firstOrFail();

        $this->assertFalse($verification->after['chain_ok']);
        $this->assertSame('failure', $verification->result);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText('Last verification');
    }

    public function test_a_closed_period_is_sealed_and_re_verifies(): void
    {
        $previousMonth = now()->subMonthNoOverflow();

        Carbon::setTestNow($previousMonth->copy()->startOfMonth()->addDays(3));
        $this->event('sales.invoice_issued', ['reason' => 'sealed one']);
        $this->event('sales.payment_recorded', ['reason' => 'sealed two']);

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $period = $previousMonth->format('Y-m');

        $this->artisan('erp:audit:seal', ['--period' => $period])
            ->expectsOutputToContain('verified')
            ->assertExitCode(0);

        $archive = AuditArchive::query()->where('period', $period)->firstOrFail();

        $this->assertSame(2, (int) $archive->event_count);
        $this->assertNotNull($archive->chain_start_hash);
        $this->assertNotNull($archive->chain_end_hash);
        $this->assertSame(64, strlen((string) $archive->checksum));

        $verifier = app(AuditChainVerifier::class);

        $this->assertTrue($verifier->verifyArchive($archive)['ok']);
        $this->assertTrue($verifier->verifyArchives((int) $this->admin->company_id)['ok']);

        // Sealing is itself an event, and it is not inside the sealed period.
        $this->assertDatabaseHas('audit_events', ['action' => 'security.audit_period_sealed']);

        // Re-sealing the same period without --force verifies what is there and
        // does not write a second seal.
        $this->artisan('erp:audit:seal', ['--period' => $period])->assertExitCode(0);

        $this->assertSame(1, AuditArchive::query()->where('period', $period)->count());

        $this->artisan('erp:chain-verify', ['--archives' => true, '--company' => $this->admin->company_id])
            ->expectsOutputToContain('Seals OK')
            ->assertExitCode(0);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText($period)
            ->assertSeeText('Matches its seal');
    }

    public function test_a_deleted_row_inside_a_sealed_period_breaks_the_seal(): void
    {
        $previousMonth = now()->subMonthNoOverflow();
        $period = $previousMonth->format('Y-m');

        Carbon::setTestNow($previousMonth->copy()->startOfMonth()->addDays(3));
        $this->event('sales.invoice_issued', ['reason' => 'row one']);
        $doomed = $this->event('sales.invoice_issued', ['reason' => 'row two']);
        $this->event('sales.invoice_issued', ['reason' => 'row three']);

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->artisan('erp:audit:seal', ['--period' => $period])->assertExitCode(0);

        $archive = AuditArchive::query()->where('period', $period)->firstOrFail();
        $verifier = app(AuditChainVerifier::class);

        $this->assertTrue($verifier->verifyArchive($archive)['ok']);

        // Removing evidence: the chain alone cannot notice a row that is gone —
        // the seal is what notices.
        DB::table('audit_events')->where('id', $doomed->id)->delete();

        $result = $verifier->verifyArchive($archive->fresh());

        $this->assertFalse($result['ok']);
        $this->assertSame('archive_count_mismatch', $result['reason']);
        $this->assertSame(2, $result['checked']);

        $chain = $verifier->verify((int) $this->admin->company_id);

        $this->assertFalse($chain['ok']);
        $this->assertSame('sequence_gap', $chain['reason']);

        $this->artisan('erp:chain-verify', ['--archives' => true, '--company' => $this->admin->company_id])
            ->assertExitCode(1);

        $this->actingAs($this->admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSeeText('archive count mismatch');
    }

    public function test_sealing_refuses_a_broken_chain_and_an_open_period(): void
    {
        $previousMonth = now()->subMonthNoOverflow();

        Carbon::setTestNow($previousMonth->copy()->startOfMonth()->addDays(3));
        $event = $this->event('sales.invoice_issued', ['reason' => 'to be edited']);
        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'edited before sealing']);

        $this->artisan('erp:audit:seal', ['--period' => $previousMonth->format('Y-m')])
            ->expectsOutputToContain('Refusing to seal')
            ->assertExitCode(1);

        $this->assertSame(0, AuditArchive::query()->count());

        // A period that has not closed cannot be sealed either — and that is
        // refused before the chain is even consulted.
        $this->artisan('erp:audit:seal', ['--period' => now()->format('Y-m')])
            ->expectsOutputToContain('has not finished')
            ->assertExitCode(1);

        // Put the edited field back so the chain verifies again: a month with no
        // events then writes no empty seal.
        DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'to be edited']);

        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $this->admin->company_id)['ok']);

        $this->artisan('erp:audit:seal', ['--period' => '2020-01'])
            ->expectsOutputToContain('Nothing to seal')
            ->assertExitCode(0);

        $this->assertSame(0, AuditArchive::query()->count());
    }

    /* ---------------------------------------------------------- the doors */

    public function test_reading_the_trail_needs_its_key_and_the_report_leaves_a_paper_trail(): void
    {
        $this->event('sales.invoice_issued', ['reason' => 'a fact worth printing']);

        $reader = $this->userWith(['audit.view']);

        $this->actingAs($reader)->get(route('audit.index'))->assertOk()->assertSeeText('Audit log');

        // No export key: neither the CSV nor the printable report is offered.
        $this->actingAs($reader)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertDontSeeText('Printable report');

        $this->actingAs($reader)->get(route('audit.export'))->assertForbidden();
        $this->actingAs($reader)
            ->get(route('documents.print.show', ['type' => 'audit_report', 'id' => 0]))
            ->assertForbidden();

        $exporter = $this->userWith(['audit.view', 'audit.export']);

        $csv = $this->actingAs($exporter)
            ->get(route('audit.export'))
            ->assertOk();

        $this->assertStringContainsString('seq,created_at,action', $csv->streamedContent());

        // The export is itself audited.
        $this->assertDatabaseHas('audit_events', ['action' => 'document.export']);

        $this->actingAs($exporter)
            ->get(route('documents.print.show', ['type' => 'audit_report', 'id' => 0]))
            ->assertOk()
            ->assertSeeText('AUDIT TRAIL REPORT')
            ->assertSeeText('a fact worth printing');

        $this->assertSame(1, PrintHistory::query()->where('printed_title', 'AUDIT TRAIL REPORT')->count());
    }

    /** @return array<int, string> */
    protected function sourceFiles(): array
    {
        $files = [];

        foreach (['app', 'database/seeders', 'routes'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory)),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
