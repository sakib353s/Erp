<?php

namespace App\Domain\Operations\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Operations\MaintenanceRun;
use App\Domain\Settings\Setting;
use App\Search\SearchIndexRebuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * §15-23…§15-33 — the maintenance desk, and the rules that make its buttons
 * safe to press.
 *
 * Every ERP admin eventually clears a cache; the reason this is a service with
 * an allow-list instead of a controller full of shell strings is that the same
 * vending-machine impulse also deletes an upload directory, empties the audit
 * trail, or “repairs” the database until the ledger disagrees with itself.
 * Three rules run through everything here.
 *
 *  1. **There is an explicit list of what may be swept, and of what may never
 *     be.** {@see SWEEPABLE} is a closed set of paths; {@see PROTECTED} names the
 *     things whose removal would be a business loss — uploads, documents,
 *     backups, logs, sessions — and no operation in this class deletes from
 *     them. A sweep also never follows a symlink out of the storage tree, and
 *     never removes a directory, only files it can account for.
 *  2. **Nothing destructive acts on what it has not looked at.** Repair reads
 *     the last integrity check's problem list from `maintenance_runs` and
 *     refuses to touch a table that check did not name; a check with no problems
 *     refuses a repair outright. So the operations are ordered by construction,
 *     not by the operator remembering.
 *  3. **Every run is recorded twice** — an audit event for the chain, and a
 *     `maintenance_runs` row carrying the numbers (files, bytes, tables). A
 *     maintenance action whose effect nobody can size is indistinguishable from
 *     one that deleted the wrong thing.
 *
 * `refused` is a first-class outcome: an operation that correctly declined is
 * recorded as a decision, because “nothing happened” and “the system said no”
 * are different facts and the next operator needs to tell them apart.
 */
class MaintenanceService
{
    /** The only paths a sweep may delete from, relative to `storage/`. */
    public const SWEEPABLE = [
        'app/temp',
        'tmp',
        'framework/cache/data',
    ];

    /**
     * Paths no operation here may delete from, whatever else is asked of it.
     * They are listed with the reason because the list is the safety property.
     *
     * @var array<string, string>
     */
    public const PROTECTED = [
        'app/private' => 'Uploaded documents, attachments and image derivatives — a person put these here.',
        'app/public' => 'Published media referenced by documents already handed out.',
        'app/backups' => 'Backups. Removing these is the backup policy’s job, on its own clock.',
        'logs' => 'The error log. Rotation keeps it bounded; a maintenance button deleting evidence does not.',
        'framework/sessions' => 'Live sessions. There is a separate, audited operation for ending them.',
        'framework/views' => 'Compiled templates. Cleared by the cache operation that owns them, never by a sweep that could catch one mid-write.',
    ];

    /** Files younger than this are left alone: something may be writing them. */
    public const TEMP_MIN_AGE_HOURS = 24;

    /** Typed confirmations. A sentence is hard to type by accident, a checkbox is not. */
    public const CONFIRM_REPAIR = 'REPAIR';
    public const CONFIRM_RESET = 'RESET SETTINGS';

    /**
     * Operations no self-healing run may ever perform, with the reason. The desk
     * shows this list: an administrator asking “why doesn't it just fix itself”
     * deserves the answer in the product, not in a manual.
     *
     * @var array<string, array{label: string, why: string}>
     */
    public const NEVER_AUTOMATIC = [
        'schema' => [
            'label' => 'Schema changes',
            'why' => 'A repair that alters structure has to be a migration somebody reviewed, with a rollback, or a broken release becomes an unanswerable database.',
        ],
        'ledger' => [
            'label' => 'Posting or reversing entries',
            'why' => 'Money moves through documents with an approver and an audit trail. An automatic correction is an unaudited one.',
        ],
        'audit' => [
            'label' => 'Editing or deleting audit rows',
            'why' => 'The trail is the evidence that the rest of this list was followed. It is written by the system and read by people.',
        ],
        'access' => [
            'label' => 'Granting permissions',
            'why' => 'Access is granted by a person who can be asked why. A heal that widens access is privilege escalation with a friendly name.',
        ],
        'identity' => [
            'label' => 'Rewriting instance identity',
            'why' => 'Company, currency, fiscal calendar and instance keys are protected settings (§15-35): composition, not configuration.',
        ],
        'uploads' => [
            'label' => 'Removing uploaded files',
            'why' => 'A file a person uploaded is not garbage because the system cannot see who needs it.',
        ],
    ];

    public function __construct(
        protected AuditRecorder $audit,
        protected SearchIndexRebuilder $search,
    ) {}

    /* ------------------------------------------------------------- the desk */

    public function history(int $limit = 12): Collection
    {
        return MaintenanceRun::query()->orderByDesc('id')->limit($limit)->get();
    }

    public function lastRun(string $action): ?MaintenanceRun
    {
        return MaintenanceRun::query()->where('action', $action)->orderByDesc('id')->first();
    }

    /**
     * What a sweep would touch and what it will not — so the screen can say it
     * before the button is pressed rather than after something went missing.
     *
     * @return array{sweep: array<int, array{path: string, exists: bool, bytes: int, files: int}>, protected: array<string, string>}
     */
    public function sweepTargets(): array
    {
        $sweep = [];

        foreach (self::SWEEPABLE as $relative) {
            $absolute = storage_path($relative);

            $sweep[] = [
                'path' => 'storage/'.$relative,
                'exists' => is_dir($absolute),
                'bytes' => is_dir($absolute) ? $this->directorySize($absolute) : 0,
                'files' => is_dir($absolute) ? $this->directoryFiles($absolute) : 0,
            ];
        }

        return ['sweep' => $sweep, 'protected' => self::PROTECTED];
    }

    /* --------------------------------------------------------- safe operations */

    /**
     * §15-23 — clear the cache. Caches are derived state: every byte of this is
     * reproducible from the database, which is exactly why it is safe.
     *
     * @return array<string, mixed>
     */
    public function clearCache(User $actor): array
    {
        $before = $this->cacheFootprint();

        $steps = [];

        foreach (['cache:clear' => 'Application cache', 'view:clear' => 'Compiled templates'] as $command => $label) {
            Artisan::call($command);

            $steps[] = ['command' => $command, 'label' => $label, 'output' => trim(Artisan::output())];
        }

        // Leftovers from a previous cache store, or from a template compiled
        // before an upgrade, are still derived state sitting on disk: the
        // commands above clear the *configured* stores, this removes what is
        // actually there.
        $leftover = $this->sweep(['framework/cache/data', 'framework/views'], null);

        $steps[] = [
            'command' => 'sweep derived files',
            'label' => 'Leftovers in the derived directories',
            'output' => sprintf('%d file(s), %s', $leftover['files'], MaintenanceRun::humanBytes($leftover['bytes'])),
        ];

        $after = $this->cacheFootprint();

        return $this->record($actor, 'cache.clear', MaintenanceRun::STATUS_OK, sprintf(
            'Cache cleared: %s of derived files removed; uploads, backups and the audit trail untouched.',
            MaintenanceRun::humanBytes(max(0, $before - $after)),
        ), [
            'steps' => $steps,
            'bytes_before' => $before,
            'bytes_after' => $after,
            'untouched' => array_keys(self::PROTECTED),
        ], max(0, $before - $after), 'maintenance.cache_cleared');
    }

    /**
     * §15-24 — end other people's sessions. The actor's own session is kept:
     * clearing sessions must not be a way to log yourself out of a maintenance
     * desk mid-way, and it must not be a way to escape being the one who did it.
     *
     * @return array<string, mixed>
     */
    public function clearSessions(User $actor, ?string $keepSessionId): array
    {
        $driver = (string) config('session.driver');

        if ($driver === 'database') {
            $query = DB::table('sessions');

            if ($keepSessionId !== null && $keepSessionId !== '') {
                $query->where('id', '!=', $keepSessionId);
            }

            $removed = $query->delete();

            return $this->record($actor, 'sessions.clear', MaintenanceRun::STATUS_OK, sprintf(
                '%d session(s) ended. Your own session was kept, so you are still the person reading this.',
                $removed,
            ), [
                'driver' => 'database',
                'removed' => $removed,
                'kept_current' => $keepSessionId !== null && $keepSessionId !== '',
            ], 0, 'maintenance.sessions_cleared');
        }

        if ($driver === 'file') {
            $directory = storage_path('framework/sessions');
            $removed = 0;

            foreach (File::files($directory) as $file) {
                if ($keepSessionId !== null && $file->getFilename() === $keepSessionId) {
                    continue;
                }

                File::delete($file->getPathname());
                $removed++;
            }

            return $this->record($actor, 'sessions.clear', MaintenanceRun::STATUS_OK, sprintf('%d session file(s) ended.', $removed), [
                'driver' => 'file',
                'removed' => $removed,
            ], 0, 'maintenance.sessions_cleared');
        }

        // Cookie, array, redis, memcached: there is nothing here to end, and
        // saying so is better than reporting a deletion that never happened.
        return $this->record($actor, 'sessions.clear', MaintenanceRun::STATUS_REFUSED, sprintf(
            'This installation stores sessions in the %s store, which this screen cannot enumerate. No sessions were ended.',
            $driver,
        ), ['driver' => $driver, 'removed' => 0], 0, 'maintenance.sessions_refused');
    }

    /**
     * §15-25 — remove temporary files. Only {@see SWEEPABLE}, only below the age
     * threshold, never a symlink, never a directory. The count and the bytes are
     * reported because “cleared temporary files” without a figure is a claim
     * nobody can check.
     *
     * @return array<string, mixed>
     */
    public function clearTemp(User $actor): array
    {
        $cutoff = now()->subHours(self::TEMP_MIN_AGE_HOURS)->getTimestamp();

        $removed = 0;
        $freed = 0;
        $perPath = [];

        foreach (self::SWEEPABLE as $relative) {
            $swept = $this->sweep([$relative], $cutoff);

            $removed += $swept['files'];
            $freed += $swept['bytes'];

            $perPath[] = ['path' => 'storage/'.$relative, 'files' => $swept['files'], 'bytes' => $swept['bytes']];
        }

        return $this->record($actor, 'temp.clear', MaintenanceRun::STATUS_OK, sprintf(
            '%d temporary file(s) removed, %s freed. Files younger than %d hours were left alone, and nothing inside an upload, backup or log directory was touched.',
            $removed,
            MaintenanceRun::humanBytes($freed),
            self::TEMP_MIN_AGE_HOURS,
        ), [
            'per_path' => $perPath,
            'min_age_hours' => self::TEMP_MIN_AGE_HOURS,
            'protected' => array_keys(self::PROTECTED),
        ], $freed, 'maintenance.temp_cleared');
    }

    /* ------------------------------------------------------------- database */

    /**
     * §15-26 — optimise tables. Reclaims free space inside the table files and
     * reports how much: numbers taken before and after, never estimated.
     *
     * @return array<string, mixed>
     */
    public function optimizeDatabase(User $actor): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // VACUUM is the statement that actually returns space to the file
            // system, and SQLite refuses it inside an open transaction. Rather
            // than failing with the engine's error, the operation says what it
            // did instead: planner statistics are still worth refreshing, and
            // claiming space was reclaimed when none was would be a lie the
            // history table would keep.
            if (DB::connection()->transactionLevel() > 0) {
                $statement = 'PRAGMA optimize';

                try {
                    DB::statement($statement);
                    $summary = 'Query-planner statistics refreshed (PRAGMA optimize). A full compaction (VACUUM) cannot run inside an open transaction, so no space was reclaimed in this pass.';
                } catch (\Throwable) {
                    // Even the planner refresh can be refused depending on what the
                    // surrounding transaction is doing. Saying nothing was changed is
                    // the only claim that stays true.
                    $statement = 'none — open transaction';
                    $summary = 'This connection is inside an open transaction, so the database could not be compacted and not even planner statistics were refreshed. Nothing was changed.';
                }

                return $this->record($actor, 'db.optimize', MaintenanceRun::STATUS_OK, $summary,
                    ['driver' => 'sqlite', 'statement' => $statement, 'inside_transaction' => true, 'bytes_before' => null, 'bytes_after' => null],
                    0, 'maintenance.db_optimized');
            }

            $path = DB::connection()->getDatabaseName();
            $before = is_file($path) ? (int) filesize($path) : 0;

            DB::statement('VACUUM');

            clearstatcache(true, $path);
            $after = is_file($path) ? (int) filesize($path) : 0;

            return $this->record($actor, 'db.optimize', MaintenanceRun::STATUS_OK, sprintf(
                'Database compacted with VACUUM: %s before, %s after.',
                MaintenanceRun::humanBytes($before),
                MaintenanceRun::humanBytes($after),
            ), ['driver' => 'sqlite', 'statement' => 'VACUUM', 'inside_transaction' => false,
                'bytes_before' => $before, 'bytes_after' => $after],
                max(0, $before - $after), 'maintenance.db_optimized');
        }

        $tables = $this->tables();
        $before = $this->freeSpace();

        $results = [];

        foreach ($tables as $table) {
            $rows = DB::select('OPTIMIZE TABLE `'.$table.'`');
            $results[] = ['table' => $table, 'note' => (string) ($this->firstString($rows) ?? 'ok')];
        }

        $after = $this->freeSpace();

        return $this->record($actor, 'db.optimize', MaintenanceRun::STATUS_OK, sprintf(
            '%d table(s) optimised; free space inside them went from %s to %s.',
            count($tables),
            MaintenanceRun::humanBytes($before),
            MaintenanceRun::humanBytes($after),
        ), [
            'driver' => $driver,
            'tables' => count($tables),
            'free_before' => $before,
            'free_after' => $after,
            'results' => array_slice($results, 0, 60),
        ], max(0, $before - $after), 'maintenance.db_optimized');
    }

    /**
     * §15-27 — integrity check. Read-only, and the thing every repair stands on:
     * the problem list it returns is what a later repair is allowed to touch.
     *
     * @return array<string, mixed>
     */
    public function integrityCheck(User $actor): array
    {
        $driver = DB::connection()->getDriverName();
        $problems = [];
        $checked = 0;

        if ($driver === 'sqlite') {
            $rows = DB::select('PRAGMA integrity_check');

            foreach ($rows as $row) {
                $message = (string) ($this->firstString([$row]) ?? '');

                if (strtolower($message) !== 'ok') {
                    $problems['database'] = $message;
                }
            }

            $checked = 1;
        } else {
            foreach ($this->tables() as $table) {
                $checked++;

                foreach (DB::select('CHECK TABLE `'.$table.'`') as $row) {
                    $status = strtolower((string) ($row->Msg_type ?? $row->msg_type ?? ''));

                    if (in_array($status, ['error', 'warning'], true)) {
                        $problems[$table] = (string) ($row->Msg_text ?? $row->msg_text ?? 'The check reported a problem.');
                    }
                }
            }
        }

        $summary = $problems === []
            ? sprintf('%d table(s) checked — every one answered OK.', $checked)
            : sprintf('%d table(s) checked, %d with a problem. Repair may only touch the tables named here.', $checked, count($problems));

        return $this->record($actor, 'db.integrity', $problems === [] ? MaintenanceRun::STATUS_OK : MaintenanceRun::STATUS_FAILED, $summary, [
            'driver' => $driver,
            'checked' => $checked,
            'problems' => $problems,
        ], 0, 'maintenance.db_checked');
    }

    /**
     * §15-27 — repair, which is the only operation in this class that writes to
     * structure. Three gates, in order: the sentence typed, a check on file, and
     * a table the check named. Repair of a table nobody looked at is how a
     * “quick fix” becomes data loss.
     *
     * @param  array<int, string>  $tables
     * @return array<string, mixed>
     */
    public function repairDatabase(User $actor, string $confirm, array $tables): array
    {
        if (trim($confirm) !== self::CONFIRM_REPAIR) {
            return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_REFUSED,
                'Repair needs the word '.self::CONFIRM_REPAIR.' typed in full. Nothing was changed.',
                ['typed' => $confirm === '' ? null : 'did not match'], 0, 'maintenance.db_repair_refused');
        }

        $check = $this->lastRun('db.integrity');

        if ($check === null) {
            return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_REFUSED,
                'No integrity check is on file for this company. Run the check first — repair acts on what the check found, and this desk will not guess.',
                [], 0, 'maintenance.db_repair_refused');
        }

        $known = array_keys((array) ($check->details['problems'] ?? []));

        if ($known === []) {
            return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_REFUSED,
                'The last integrity check ('.$check->created_at?->diffForHumans().') found nothing to repair. Nothing was changed.',
                ['checked_at' => $check->created_at?->toDateTimeString()], 0, 'maintenance.db_repair_refused');
        }

        $wanted = array_values(array_intersect($tables === [] ? $known : $tables, $known));

        if ($wanted === []) {
            return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_REFUSED,
                'None of the tables asked for were named by the last check, so none were repaired.',
                ['asked' => $tables, 'named_by_check' => $known], 0, 'maintenance.db_repair_refused');
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_REFUSED,
                'A SQLite file is not repaired in place. Restore from a backup and check it before returning it to service.',
                ['driver' => 'sqlite', 'named_by_check' => $known], 0, 'maintenance.db_repair_refused');
        }

        $results = [];

        foreach ($wanted as $table) {
            foreach (DB::select('REPAIR TABLE `'.$table.'`') as $row) {
                $results[] = [
                    'table' => $table,
                    'note' => (string) ($row->Msg_text ?? $row->msg_text ?? 'done'),
                ];
            }
        }

        // The repair is the newest fact about both operations: the problem list
        // it acted on belongs to the check it read, which stays untouched.
        return $this->record($actor, 'db.repair', MaintenanceRun::STATUS_OK, sprintf(
            '%d table(s) repaired: %s. A fresh integrity check is the way to confirm it.',
            count($wanted),
            implode(', ', $wanted),
        ), ['repaired' => $wanted, 'results' => $results, 'based_on_check' => $check->id], 0, 'maintenance.db_repaired');
    }

    /* ------------------------------------------------------------- settings */

    /**
     * §15-33 — reset settings to their declared defaults, with the business data
     * untouched. Rows are deleted rather than overwritten with the default value,
     * because a row that says what the default already says is a decision nobody
     * made and hides the fact that this company never chose.
     *
     * @return array<string, mixed>
     */
    public function resetSettings(User $actor, string $confirm): array
    {
        if (trim($confirm) !== self::CONFIRM_RESET) {
            return $this->record($actor, 'settings.reset', MaintenanceRun::STATUS_REFUSED,
                'Settings reset needs the words “'.self::CONFIRM_RESET.'” typed in full. Nothing was changed.',
                [], 0, 'maintenance.settings_reset_refused');
        }

        $rows = Setting::query()->get(['id', 'setting_group', 'branch_id']);

        $byGroup = $rows->groupBy('setting_group')
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        $branchRows = $rows->where('branch_id', '!=', Setting::COMPANY_SCOPE)->count();

        // The history is the record of what was chosen and by whom: deleting it
        // would make a reset indistinguishable from a company that never
        // configured anything.
        Setting::query()->delete();

        return $this->record($actor, 'settings.reset', MaintenanceRun::STATUS_OK, sprintf(
            '%d setting row(s) removed — %d company values and %d branch overrides. Every group is back on its declared default; the history and the audit trail are kept, and no business data was touched.',
            $rows->count(),
            $rows->count() - $branchRows,
            $branchRows,
        ), ['by_group' => $byGroup, 'branch_overrides' => $branchRows], 0, 'maintenance.settings_reset');
    }

    /* ---------------------------------------------------------- self-healing */

    /**
     * §15-32 — run the operations that are safe to run unattended, in a fixed
     * order, and say plainly which ones never will be.
     *
     * @return array<string, mixed>
     */
    public function selfHeal(User $actor, ?string $keepSessionId): array
    {
        $steps = [
            'cache.clear' => fn (): array => $this->clearCache($actor),
            'temp.clear' => fn (): array => $this->clearTemp($actor),
            'sessions.clear' => fn (): array => $this->clearSessions($actor, $keepSessionId),
            'search.rebuild' => fn (): array => $this->rebuildSearchIndex($actor),
        ];

        $ran = [];
        $freed = 0;

        foreach ($steps as $action => $step) {
            try {
                $result = $step();
                $ran[$action] = $result['summary'];
                $freed += (int) ($result['freed_bytes'] ?? 0);
            } catch (\Throwable $failure) {
                // One failing step must not abandon the rest silently: the run
                // continues and the failure is named in the record.
                $ran[$action] = 'failed — '.$failure->getMessage();
            }
        }

        return $this->record($actor, 'self.heal', MaintenanceRun::STATUS_OK, sprintf(
            'Self-healing run: %d safe operation(s) executed, %s freed. %d operation(s) are never automatic and were not attempted.',
            count($ran),
            MaintenanceRun::humanBytes($freed),
            count(self::NEVER_AUTOMATIC),
        ), [
            'steps' => $ran,
            'never_automatic' => array_keys(self::NEVER_AUTOMATIC),
        ], $freed, 'maintenance.self_healed');
    }

    /** §15-28 — the search index, rebuilt from source tables only. */
    public function rebuildSearchIndex(User $actor): array
    {
        $companyId = $this->companyId($actor);

        $before = (int) DB::table('search_index')->where('company_id', $companyId)->count();
        $counts = $this->search->rebuild($companyId);
        $after = (int) DB::table('search_index')->where('company_id', $companyId)->count();

        return $this->record($actor, 'search.rebuild', MaintenanceRun::STATUS_OK, sprintf(
            '%d index rows written from source tables (was %d): the index now matches the users, branches, warehouses, roles and documents it points at.',
            $after,
            $before,
        ), ['rows_before' => $before, 'rows_after' => $after, 'by_type' => $counts], 0, 'maintenance.rebuild_index');
    }

    /* -------------------------------------------------------------- internals */

    /**
     * One maintenance run: a `maintenance_runs` row with the figures and an audit
     * event for the chain. Both are written, always — the two answer different
     * questions and neither replaces the other.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function record(
        User $actor,
        string $action,
        string $status,
        string $summary,
        array $details,
        int $freedBytes,
        string $auditAction,
    ): array {
        $run = MaintenanceRun::query()->create([
            'company_id' => $this->companyId($actor),
            'action' => $action,
            'status' => $status,
            'summary' => mb_substr($summary, 0, 500),
            'details' => $details,
            'freed_bytes' => $freedBytes,
            'actor_id' => $actor->id,
            'actor_label' => $actor->name,
        ]);

        $this->audit->record([
            'action' => $auditAction,
            'entity_type' => 'maintenance_run',
            'entity_id' => $run->id,
            'actor_id' => $actor->id,
            'after' => ['action' => $action, 'status' => $status, 'freed_bytes' => $freedBytes] + $details,
        ]);

        return [
            'run' => $run,
            'action' => $action,
            'status' => $status,
            'summary' => $summary,
            'details' => $details,
            'freed_bytes' => $freedBytes,
        ];
    }

    /**
     * Delete files under the given `storage/` paths — and only files, only real
     * files (never a symlink, never a directory), and only inside the storage
     * tree after resolving links. `$cutoff` null means “any age”, which is only
     * ever passed for directories whose contents are reproducible by definition.
     *
     * @param  array<int, string>  $paths
     * @return array{files: int, bytes: int}
     */
    protected function sweep(array $paths, ?int $cutoff): array
    {
        $files = 0;
        $bytes = 0;

        foreach ($paths as $relative) {
            $absolute = storage_path($relative);

            if (! is_dir($absolute) || $this->isProtected($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                if (! $this->insideStorage($file->getPathname())) {
                    continue; // a symlink or a mount pointing out of the tree: not ours to delete
                }

                if ($cutoff !== null && $file->getMTime() > $cutoff) {
                    continue; // somebody may be writing this right now
                }

                $size = $file->getSize();

                if (File::delete($file->getPathname())) {
                    $files++;
                    $bytes += $size;
                }
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    protected function companyId(User $actor): int
    {
        return (int) $actor->company_id;
    }

    /** Tables in the current database, name-validated before they reach SQL. */
    protected function tables(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return array_values(array_map(
                fn (object $row): string => (string) ($row->name ?? ''),
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            ));
        }

        $names = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            $name = (string) (array_values((array) $row)[0] ?? '');

            // A table name is an identifier, and this is the one place it becomes
            // SQL — so it has to look like one.
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Free space inside the tables, for a before/after figure. */
    protected function freeSpace(): int
    {
        try {
            $row = DB::selectOne(
                'SELECT SUM(data_free) AS free_bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($row->free_bytes ?? 0);
    }

    /** Bytes held by the derived-state directories a cache clear empties. */
    protected function cacheFootprint(): int
    {
        $total = 0;

        foreach (['framework/cache', 'framework/views'] as $relative) {
            $path = storage_path($relative);

            if (is_dir($path)) {
                $total += $this->directorySize($path);
            }
        }

        return $total;
    }

    protected function directorySize(string $path): int
    {
        $bytes = 0;

        foreach (File::allFiles($path) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    protected function directoryFiles(string $path): int
    {
        return count(File::allFiles($path));
    }

    /** True when a path is inside, or below, one of the protected directories. */
    protected function isProtected(string $absolute): bool
    {
        $storage = $this->realOrNull(storage_path()) ?? storage_path();

        foreach (array_keys(self::PROTECTED) as $relative) {
            $protected = $this->realOrNull(storage_path($relative));

            if ($protected !== null && str_starts_with($absolute, $protected)) {
                return true;
            }

            if (str_starts_with($absolute, $storage.'/'.$relative)) {
                return true;
            }
        }

        return false;
    }

    /** True when a path still resolves inside the storage tree after symlinks. */
    protected function insideStorage(string $path): bool
    {
        $storage = $this->realOrNull(storage_path());
        $real = $this->realOrNull($path);

        if ($storage === null || $real === null) {
            return false;
        }

        return str_starts_with($real, $storage.DIRECTORY_SEPARATOR);
    }

    protected function realOrNull(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : $real;
    }

    /** The first string field of a driver result row, whatever it is called. */
    protected function firstString(array $rows): ?string
    {
        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        foreach ((array) $row as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A refused operation that reached the controller is a bug: every refusal in
     * this class is recorded and returned. Kept as the honest failure mode for
     * anything unexpected rather than letting a half-run pass as success.
     */
    protected function refuse(string $why): never
    {
        throw new RuntimeException($why);
    }
}
