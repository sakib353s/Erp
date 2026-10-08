<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Operations\MaintenanceRun;
use App\Domain\Operations\Services\LogReader;
use App\Domain\Operations\Services\LogRedactor;
use App\Domain\Operations\Services\MaintenanceService;
use App\Domain\Settings\Services\SettingService;
use App\Search\SearchIndex;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §15-23…§15-33 — the maintenance desk.
 *
 * A maintenance screen is judged by two things it must never do: delete
 * something a person needed, and describe what it did in words that are not
 * true. So the tests below come in pairs — the operation works, *and* the thing
 * next to it survived; the refusal is recorded, *and* it says which gate stopped
 * it. Where an operation cannot run (SQLite cannot compact inside an open
 * transaction) the test asserts the honest smaller claim rather than a green
 * tick.
 */
class MaintenanceDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    /** Files this test created under storage/, removed again afterwards. */
    protected array $scratch = [];

    /** Directories this test created under storage/ (deepest last), removed if empty. */
    protected array $scratchDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        // Only files this test wrote, plus the directory that holds them when it
        // was created for the test and is now empty. Storage directories that
        // belong to the application are left exactly as they were found.
        foreach ($this->scratch as $path) {
            if (File::isFile($path)) {
                File::delete($path);
            }
        }

        // Only directories that did not exist before this test: `storage/logs`
        // and friends belong to the application, not to the test.
        foreach (array_reverse($this->scratchDirs) as $directory) {
            @rmdir($directory);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    protected function service(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    protected function scratchFile(string $relative, string $contents = 'x', ?int $ageHours = null): string
    {
        $path = storage_path($relative);

        $directory = dirname($path);

        while (! File::isDirectory($directory) && str_starts_with($directory, storage_path())) {
            $this->scratchDirs[] = $directory;
            $directory = dirname($directory);
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        if ($ageHours !== null) {
            touch($path, now()->subHours($ageHours)->getTimestamp());
        }

        $this->scratch[] = $path;

        return $path;
    }

    protected function reader(array $keys): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($keys)->id);

        return $user;
    }

    protected function audits(string $action): int
    {
        return DB::table('audit_events')->where('action', $action)->count();
    }

    // ------------------------------------------------------------- the desk

    public function test_the_desk_reports_the_system_rather_than_a_badge(): void
    {
        $response = $this->actingAs($this->admin)->get(route('maintenance.index'));

        $response->assertOk();
        $response->assertSee('What this page does not claim');
        $response->assertSee('single instance');
        $response->assertSee('no replica');
        $response->assertSee('not built in this build');
        $response->assertSee(PHP_VERSION);
        $response->assertSee('Maintenance history');
        $response->assertSee('storage/app/private');
    }

    public function test_the_desk_lists_what_it_will_never_do_automatically(): void
    {
        $response = $this->actingAs($this->admin)->get(route('maintenance.index'));

        foreach (MaintenanceService::NEVER_AUTOMATIC as $operation) {
            $response->assertSee($operation['label']);
        }

        $response->assertSee('A repair that alters structure has to be a migration somebody reviewed');
    }

    public function test_each_maintenance_action_carries_its_own_key(): void
    {
        $cacheOnly = $this->reader(['maintenance.index', 'maintenance.cache']);

        $this->actingAs($cacheOnly)->get(route('maintenance.index'))->assertOk();

        $this->actingAs($cacheOnly)
            ->post(route('maintenance.cache.clear'))
            ->assertRedirect();

        // Holding the cache key is not holding the database key.
        $this->actingAs($cacheOnly)
            ->post(route('maintenance.database.optimize'))
            ->assertForbidden();

        $this->actingAs($cacheOnly)
            ->post(route('maintenance.settings.reset'), ['confirm' => MaintenanceService::CONFIRM_RESET])
            ->assertForbidden();

        // The desk itself is behind its own key.
        $this->actingAs($this->reader(['maintenance.cache']))
            ->get(route('maintenance.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------ §15-23 clear cache

    public function test_clearing_the_cache_does_not_touch_uploads_logs_or_the_backups_directory(): void
    {
        $upload = $this->scratchFile('app/private/2026/10/invoice-scan.jpg', 'a real upload');
        $log = $this->scratchFile('logs/laravel-2026-10-01.log', '[2026-10-01 09:00:00] local.ERROR: earlier failure');
        $backup = $this->scratchFile('app/backups/2026-10-01.sql', 'a dump somebody took');
        $derived = $this->scratchFile('framework/cache/data/derived-cache-file', 'reproducible');

        $result = $this->service()->clearCache($this->admin);

        $this->assertSame('ok', $result['status']);
        $this->assertFileExists($upload);
        $this->assertFileExists($log);
        $this->assertFileExists($backup);
        $this->assertFileDoesNotExist($derived, 'a derived cache file is exactly what this operation may remove');

        $this->assertSame(1, MaintenanceRun::query()->where('action', 'cache.clear')->where('status', 'ok')->count());
        $this->assertSame(1, $this->audits('maintenance.cache_cleared'));
    }

    // ---------------------------------------------- §15-24 clear sessions

    public function test_ending_sessions_keeps_the_operators_own(): void
    {
        DB::table('sessions')->insert([
            ['id' => 'mine', 'user_id' => $this->admin->id, 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
            ['id' => 'counter-1', 'user_id' => null, 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
            ['id' => 'counter-2', 'user_id' => null, 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ]);

        $result = $this->service()->clearSessions($this->admin, 'mine');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['details']['removed']);
        $this->assertSame(['mine'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(1, $this->audits('maintenance.sessions_cleared'));
    }

    public function test_a_session_store_this_screen_cannot_enumerate_is_refused_rather_than_reported_as_cleared(): void
    {
        config(['session.driver' => 'redis']);

        $result = $this->service()->clearSessions($this->admin, 'mine');

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('cannot enumerate', $result['summary']);
        $this->assertSame(1, MaintenanceRun::query()->where('action', 'sessions.clear')->where('status', 'refused')->count());
    }

    // ------------------------------------------------- §15-25 clear temp

    public function test_the_temp_sweep_takes_old_files_and_leaves_everything_else(): void
    {
        $old = $this->scratchFile('app/temp/old-export.tmp', 'old', 48);
        $young = $this->scratchFile('app/temp/being-written.tmp', 'young', 1);
        $upload = $this->scratchFile('app/private/keep-me.txt', 'an upload');
        $log = $this->scratchFile('logs/laravel.log', 'evidence');

        $result = $this->service()->clearTemp($this->admin);

        $this->assertSame('ok', $result['status']);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($young, 'a file younger than the threshold may be being written right now');
        $this->assertFileExists($upload);
        $this->assertFileExists($log);

        $this->assertSame(1, $result['details']['per_path'][0]['files'] ?? 0);
        $this->assertSame(1, $this->audits('maintenance.temp_cleared'));
    }

    public function test_no_sweep_path_is_inside_a_protected_path(): void
    {
        foreach (MaintenanceService::SWEEPABLE as $sweep) {
            foreach (array_keys(MaintenanceService::PROTECTED) as $protected) {
                $this->assertFalse(
                    str_starts_with($sweep, $protected),
                    sprintf('storage/%s may not be swept: it is inside the protected %s.', $sweep, $protected),
                );
            }
        }

        $this->assertArrayHasKey('app/private', MaintenanceService::PROTECTED);
        $this->assertArrayHasKey('logs', MaintenanceService::PROTECTED);
    }

    // --------------------------------------------- §15-26/27 database

    public function test_optimise_records_what_it_actually_did(): void
    {
        $result = $this->service()->optimizeDatabase($this->admin);

        $this->assertSame('ok', $result['status']);
        $this->assertNotSame('', (string) ($result['details']['statement'] ?? ''));

        // Inside the test's transaction SQLite cannot VACUUM, and the record says
        // so instead of claiming space came back.
        if ($result['details']['inside_transaction'] === true) {
            $this->assertStringContainsString('transaction', $result['summary']);
            $this->assertSame(0, $result['freed_bytes']);
        }

        $this->assertSame(1, $this->audits('maintenance.db_optimized'));
    }

    public function test_an_integrity_check_reads_every_table_and_reports_a_healthy_database(): void
    {
        $result = $this->service()->integrityCheck($this->admin);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['details']['problems']);
        $this->assertGreaterThan(10, $result['details']['checked'], 'a real installation has far more than ten tables');

        $this->assertSame(1, $this->audits('maintenance.db_checked'));
        $this->assertSame('db.integrity', MaintenanceRun::query()->where('action', 'db.integrity')->value('action'));
    }

    public function test_repair_refuses_without_the_typed_word(): void
    {
        $this->service()->integrityCheck($this->admin);

        $result = $this->service()->repairDatabase($this->admin, 'repair', []);

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString(MaintenanceService::CONFIRM_REPAIR, $result['summary']);
        $this->assertSame(1, MaintenanceRun::query()->where('action', 'db.repair')->where('status', 'refused')->count());
    }

    public function test_repair_refuses_when_no_check_is_on_file(): void
    {
        $result = $this->service()->repairDatabase($this->admin, MaintenanceService::CONFIRM_REPAIR, []);

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('Run the check first', $result['summary']);
        $this->assertSame(1, $this->audits('maintenance.db_repair_refused'));
    }

    public function test_repair_refuses_tables_the_check_never_named(): void
    {
        // A check of a healthy database: problems list is empty, so the third
        // gate closes even with the word typed.
        $this->service()->integrityCheck($this->admin);

        $result = $this->service()->repairDatabase($this->admin, MaintenanceService::CONFIRM_REPAIR, ['users']);

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('found nothing to repair', $result['summary']);
    }

    public function test_repair_never_runs_from_the_heal_operation(): void
    {
        $heal = $this->service()->selfHeal($this->admin, 'session-id');

        $this->assertArrayNotHasKey('db.repair', $heal['details']['steps']);
        $this->assertArrayNotHasKey('db.optimize', $heal['details']['steps']);
        $this->assertSame(0, MaintenanceRun::query()->where('action', 'db.repair')->count());
    }

    // ------------------------------------------------ §15-28 search index

    public function test_rebuilding_the_search_index_matches_the_source_tables(): void
    {
        SearchIndex::query()->delete();

        $result = $this->service()->rebuildSearchIndex($this->admin);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            (int) DB::table('users')->where('company_id', $this->admin->company_id)->count(),
            (int) DB::table('search_index')->where('company_id', $this->admin->company_id)->where('entity_type', 'user')->count(),
        );
        $this->assertSame(1, $this->audits('maintenance.rebuild_index'));
    }

    // --------------------------------------------------- §15-31 the log

    public function test_the_log_viewer_reads_entries_newest_first_and_filters_by_level(): void
    {
        $this->scratchFile('logs/laravel.log', implode("\n", [
            '[2026-10-08 09:00:00] production.INFO: an older note',
            '[2026-10-08 10:00:00] production.ERROR: Invoice number allocation failed',
            '#0 /app/Domain/Sales/Services/NumberingService.php(41): allocate()',
            '[2026-10-08 11:00:00] production.WARNING: Stock below reorder point for SKU-1',
        ]));

        $reader = app(LogReader::class);
        $all = $reader->entries('laravel.log', 50);

        $this->assertSame(3, $all['scanned']);
        $this->assertSame('WARNING', strtoupper($all['entries'][0]['level']), 'the newest entry leads');
        $this->assertStringContainsString('Stock below reorder point', $all['entries'][0]['message']);

        // The trace belongs to its entry, not to the next one.
        $error = collect($all['entries'])->firstWhere('level', 'error');
        $this->assertStringContainsString('NumberingService', (string) $error['trace']);

        $errorsOnly = $reader->entries('laravel.log', 50, 'error');
        $this->assertCount(1, $errorsOnly['entries']);

        $this->actingAs($this->admin)
            ->get(route('maintenance.logs', ['level' => 'error']))
            ->assertOk()
            ->assertSee('Invoice number allocation failed')
            ->assertDontSee('Stock below reorder point');
    }

    public function test_the_log_viewer_masks_secrets_before_they_reach_the_page(): void
    {
        $this->scratchFile('logs/laravel.log', implode("\n", [
            '[2026-10-08 12:00:00] production.ERROR: SMTP send failed for password=hunter2 token=abc123def456ghi789jkl012',
            '[2026-10-08 12:01:00] production.ERROR: Mail to rakib.hossain@example.com bounced',
        ]));

        $view = $this->actingAs($this->admin)->get(route('maintenance.logs'));

        $view->assertOk();
        $view->assertDontSee('hunter2');
        $view->assertDontSee('abc123def456ghi789jkl012');
        $view->assertDontSee('rakib.hossain@example.com');
        $view->assertSee('value(s) masked');
        $view->assertSee('r•••@example.com');
    }

    public function test_the_redactor_keeps_what_explains_an_error_and_masks_what_does_not(): void
    {
        $line = '[2026-10-08 12:00:00] production.ERROR: Connection refused mysql://erp_user:sup3rs3cret@10.0.0.5:3306/erp at 4111 1111 1111 1111';

        $redacted = LogRedactor::redact($line);

        $this->assertStringContainsString('Connection refused', $redacted);
        $this->assertStringContainsString('10.0.0.5:3306/erp', $redacted);
        $this->assertStringNotContainsString('sup3rs3cret', $redacted);
        $this->assertStringNotContainsString('1111 1111 1111 1111', $redacted);
        $this->assertTrue(LogRedactor::looksSensitive($line));
    }

    public function test_the_log_viewer_will_not_read_a_file_outside_the_log_directory(): void
    {
        $this->scratchFile('logs/.env', 'APP_KEY=base64:not-a-log-file');

        $response = $this->actingAs($this->admin)->get(route('maintenance.logs', ['file' => '../.env']));

        $response->assertOk();
        $response->assertSee('No readable log file by that name');
        $response->assertDontSee('not-a-log-file');
    }

    public function test_a_missing_log_file_says_so_instead_of_showing_an_empty_page(): void
    {
        foreach (File::glob(storage_path('logs/*.log')) ?: [] as $existing) {
            File::delete($existing);
        }

        $response = $this->actingAs($this->admin)->get(route('maintenance.logs'));

        $response->assertOk();
        $response->assertSee('holds no');
    }

    // ------------------------------------------------ §15-32 self-healing

    public function test_a_self_healing_run_executes_exactly_the_allowed_operations(): void
    {
        $tablesBefore = count(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"));

        $result = $this->service()->selfHeal($this->admin, 'session-id');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            ['cache.clear', 'temp.clear', 'sessions.clear', 'search.rebuild'],
            array_keys($result['details']['steps']),
        );
        $this->assertSame(array_keys(MaintenanceService::NEVER_AUTOMATIC), $result['details']['never_automatic']);

        // No schema changed: a heal may not restructure anything.
        $this->assertSame($tablesBefore, count(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'")));
        $this->assertSame(1, $this->audits('maintenance.self_healed'));
    }

    // ------------------------------------------------- §15-33 reset settings

    public function test_resetting_settings_keeps_the_business_data_and_the_history(): void
    {
        $service = app(SettingService::class);
        $service->set('general', 'decimal_places', 3, null, $this->admin);
        $service->set('labels', 'template', 'a4_3x7', $this->defaultBranch()->id, $this->admin);

        $accounts = DB::table('accounts')->count();
        $history = DB::table('setting_history')->count();
        $this->assertGreaterThan(0, DB::table('settings')->count());

        $result = $this->service()->resetSettings($this->admin, MaintenanceService::CONFIRM_RESET);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, DB::table('settings')->count(), 'every group is back on its declared default');
        $this->assertSame(1, $result['details']['branch_overrides']);

        // The evidence of what was chosen survives, and so does the company.
        $this->assertSame($history, DB::table('setting_history')->count());
        $this->assertSame($accounts, DB::table('accounts')->count());
        $this->assertSame(1, $this->audits('maintenance.settings_reset'));

        // And the default really takes over.
        $this->assertSame(
            config('erp.settings.groups.general.fields.decimal_places.default'),
            app(SettingService::class)->get('general', 'decimal_places'),
        );
    }

    public function test_resetting_settings_without_the_words_changes_nothing(): void
    {
        app(SettingService::class)->set('general', 'decimal_places', 3, null, $this->admin);

        $result = $this->service()->resetSettings($this->admin, 'reset');

        $this->assertSame('refused', $result['status']);
        $this->assertSame(1, DB::table('settings')->count());
        $this->assertSame(1, $this->audits('maintenance.settings_reset_refused'));
    }

    public function test_the_reset_form_on_the_desk_is_gated_and_asks_for_the_words(): void
    {
        $response = $this->actingAs($this->admin)->get(route('maintenance.index'));

        $response->assertOk();
        $response->assertSee(MaintenanceService::CONFIRM_RESET);
        $response->assertSee('Reset settings');
    }

    // ----------------------------------------------------- the record keeping

    public function test_every_operation_leaves_both_a_run_row_and_an_audit_event(): void
    {
        $this->actingAs($this->admin)->post(route('maintenance.cache.clear'))->assertRedirect();

        $this->assertSame(1, MaintenanceRun::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'maintenance.cache_cleared')->count());

        $run = MaintenanceRun::query()->first();
        $this->assertSame($this->admin->id, $run->actor_id);
        $this->assertSame(MaintenanceRun::STATUS_OK, $run->status);
        $this->assertSame('Cache cleared', $run->label());

        $this->actingAs($this->admin)
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('Cache cleared');
    }

    public function test_a_refusal_reaches_the_operator_as_a_warning(): void
    {
        $this->actingAs($this->admin)
            ->post(route('maintenance.database.repair'), ['confirm' => 'please'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->actingAs($this->admin)
            ->post(route('maintenance.settings.reset'), ['confirm' => 'nope'])
            ->assertRedirect()
            ->assertSessionHas('warning');
    }
}
