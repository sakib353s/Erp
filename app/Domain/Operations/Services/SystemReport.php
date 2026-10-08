<?php

namespace App\Domain\Operations\Services;

use App\Domain\Foundation\User;
use App\Domain\Operations\MaintenanceRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * §15-30 — system information, told truthfully.
 *
 * The temptation in a page like this is to fill it: a green “healthy” badge, a
 * “High availability: yes”, a backup line that says “configured”. Every one of
 * those would be a claim the installation cannot support, and an operator who
 * discovers the claim was decorative stops believing the whole page — including
 * the disk figures that were real.
 *
 * So the report is split in two:
 *
 *  · **measured** — versions, drivers, sizes, counts, limits, the age of the
 *    newest log line. Every value here is read from the running system in this
 *    request, so nothing can drift from what is actually deployed.
 *  · **claims** — the things the page deliberately does *not* assert, each with
 *    its reason: no high-availability claim (this is one installation with one
 *    database), no backup claim unless a verified run exists, queue worker and
 *    scheduler state reported as unknown because a web request cannot see them.
 *
 * The one place a badge is allowed is where a real check backs it — a fixture is
 * reported with the date it was made, a table count with the schema version.
 */
class SystemReport
{
    /**
     * Extension the application needs, with what breaks without it. Reported as
     * present/missing, never as a score.
     *
     * @var array<string, string>
     */
    public const EXTENSIONS = [
        'pdo' => 'Every database read and write',
        'mbstring' => 'Bengali text, and truncating names without breaking them',
        'openssl' => 'Encrypted settings and the audit chain',
        'bcmath' => 'Money arithmetic at four decimal places',
        'gd' => 'Image derivatives for uploaded documents',
        'zip' => 'Data export archives',
        'intl' => 'Number and date formatting per locale',
        'fileinfo' => 'Verifying an upload really is the type it claims',
    ];

    /**
     * Tables counted on the page, grouped by what a person would call them. A
     * count is the cheapest honest signal that a restore put something back.
     *
     * @var array<string, array<string, string>>
     */
    public const COUNTS = [
        'Access' => ['users' => 'Users', 'roles' => 'Roles', 'branches' => 'Branches'],
        'Trading' => [
            'customers' => 'Customers',
            'suppliers' => 'Suppliers',
            'products' => 'Products',
            'invoices' => 'Invoices',
            'payments' => 'Payments',
            'purchase_bills' => 'Purchase bills',
        ],
        'Stock' => ['warehouses' => 'Warehouses', 'stock_balances' => 'Stock balances', 'stock_layers' => 'Valuation layers'],
        'Books' => ['accounts' => 'Accounts', 'journal_entries' => 'Journal entries', 'journal_lines' => 'Journal lines'],
        'System' => ['audit_events' => 'Audit events', 'settings' => 'Setting rows', 'search_index' => 'Search index rows', 'documents' => 'Documents'],
    ];

    /** @return array<string, mixed> */
    public function build(?User $actor = null): array
    {
        return [
            'application' => $this->application(),
            'runtime' => $this->runtime(),
            'database' => $this->database(),
            'storage' => $this->storage(),
            'services' => $this->services(),
            'counts' => $this->counts(),
            'claims' => $this->claims(),
        ];
    }

    /* -------------------------------------------------------------- sections */

    /** @return array<string, mixed> */
    protected function application(): array
    {
        return [
            'name' => (string) config('app.name'),
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'timezone' => (string) config('app.timezone'),
            'locale' => (string) config('app.locale'),
            'url' => (string) config('app.url'),
            'currency' => (string) config('erp.currency.code', 'BDT'),
            // The installed version of the framework, read from the framework
            // itself rather than written into a template.
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY.' ('.php_uname('r').')',
            'server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
        ];
    }

    /** @return array<string, mixed> */
    protected function runtime(): array
    {
        $extensions = [];

        foreach (self::EXTENSIONS as $extension => $why) {
            $extensions[$extension] = ['loaded' => extension_loaded($extension), 'why' => $why];
        }

        return [
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_used' => memory_get_peak_usage(true),
            'extensions' => $extensions,
        ];
    }

    /** @return array<string, mixed> */
    protected function database(): array
    {
        $connection = DB::connection();

        return [
            'driver' => $connection->getDriverName(),
            'name' => $connection->getDatabaseName(),
            'host' => (string) (config('database.connections.'.config('database.default').'.host') ?? 'local'),
            'version' => $this->databaseVersion(),
            'size' => $this->databaseSize(),
            'tables' => $this->tableCount(),
            'migrations' => $this->migrationCount(),
        ];
    }

    /** @return array<string, mixed> */
    protected function storage(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        $directories = [];

        foreach (['app/private' => 'Uploads & documents', 'logs' => 'Log files', 'framework/cache' => 'Derived cache', 'framework/views' => 'Compiled templates'] as $relative => $label) {
            $directory = storage_path($relative);

            $directories[$relative] = [
                'label' => $label,
                'exists' => is_dir($directory),
                'bytes' => is_dir($directory) ? $this->directorySize($directory) : 0,
                'files' => is_dir($directory) ? count(File::allFiles($directory)) : 0,
            ];
        }

        $newest = null;

        foreach (File::glob(storage_path('logs/*.log')) ?: [] as $log) {
            $modified = @filemtime($log);

            if ($modified !== false && ($newest === null || $modified > $newest)) {
                $newest = $modified;
            }
        }

        return [
            'path' => $path,
            'free' => $free === false ? null : (int) $free,
            'total' => $total === false ? null : (int) $total,
            'directories' => $directories,
            'newest_log' => $newest === null ? null : Carbon::createFromTimestamp($newest)->diffForHumans(),
        ];
    }

    /** @return array<string, mixed> */
    protected function services(): array
    {
        $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
        $oldest = null;

        if ($pending !== null && $pending > 0) {
            $oldest = Carbon::createFromTimestamp((int) DB::table('jobs')->min('available_at'))->diffForHumans();
        }

        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

        return [
            'cache' => (string) config('cache.default'),
            'session' => (string) config('session.driver'),
            'queue' => (string) config('queue.default'),
            'mail' => (string) config('mail.default'),
            'filesystem' => (string) config('filesystems.default'),
            'sessions' => Schema::hasTable('sessions') ? (int) DB::table('sessions')->count() : null,
            'jobs_pending' => $pending,
            'jobs_oldest' => $oldest,
            'jobs_failed' => $failed,
        ];
    }

    /** @return array<string, array<string, array{label: string, count: ?int}>> */
    protected function counts(): array
    {
        $groups = [];

        foreach (self::COUNTS as $group => $tables) {
            foreach ($tables as $table => $label) {
                $groups[$group][$table] = [
                    'label' => $label,
                    'count' => Schema::hasTable($table) ? (int) DB::table($table)->count() : null,
                ];
            }
        }

        return $groups;
    }

    /**
     * What this page does not claim. Each line is a statement about what the
     * system can see from inside a web request, and the honest answer when it
     * cannot see it is “unknown”, not a green tick.
     *
     * @return array<int, array{label: string, verdict: string, tone: string, why: string}>
     */
    protected function claims(): array
    {
        $selfHeal = MaintenanceRun::query()->where('action', 'self.heal')->orderByDesc('id')->first();
        $lastCheck = MaintenanceRun::query()->where('action', 'db.integrity')->orderByDesc('id')->first();

        return [
            [
                'label' => 'High availability',
                'verdict' => 'single instance',
                'tone' => 'neutral',
                'why' => 'This is one application server and one database. There is no replica, no failover and no load balancer, and saying otherwise would be the least useful lie on the page.',
            ],
            [
                'label' => 'Database integrity',
                'verdict' => $lastCheck === null ? 'never checked' : $lastCheck->status.' · '.$lastCheck->created_at?->diffForHumans(),
                'tone' => $lastCheck === null ? 'warn' : ($lastCheck->status === MaintenanceRun::STATUS_OK ? 'ok' : 'warn'),
                'why' => $lastCheck === null
                    ? 'Run the integrity check on this page to know. Until then, nobody has asked the database whether its tables are sound.'
                    : $lastCheck->summary,
            ],
            [
                'label' => 'Self-healing',
                'verdict' => $selfHeal === null ? 'never run' : 'last run '.$selfHeal->created_at?->diffForHumans(),
                'tone' => 'neutral',
                'why' => 'Only derived state is healed automatically — cache, temporary files, sessions and the search index. Schema, ledger, audit trail, access and uploads are never touched by a heal, and the reasons are on this page.',
            ],
            [
                'label' => 'Backups',
                'verdict' => 'not built in this build',
                'tone' => 'warn',
                'why' => 'Backup and restore (§15-19) is not implemented, so this page will not report a backup as current. Take a database dump with your own tooling and keep the restore instructions outside the application until the module exists.',
            ],
            [
                'label' => 'Queue worker',
                'verdict' => 'unknown from here',
                'tone' => 'neutral',
                'why' => 'A web request cannot see whether a worker is running. The pending figure is real; whether anything is coming to collect it is not — check the worker process.',
            ],
            [
                'label' => 'Scheduler',
                'verdict' => 'unknown from here',
                'tone' => 'neutral',
                'why' => 'The scheduled commands (expiry alerts, bank charges, recurring expenses, chain verification) run from cron. Their absence shows up as work that never happened, not as an error on this page.',
            ],
        ];
    }

    /* ------------------------------------------------------------- internals */

    protected function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('SELECT VERSION() AS version');

            return $row->version === null ? null : (string) $row->version;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function databaseSize(): ?int
    {
        try {
            $row = DB::selectOne(
                'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
            );

            return $row->bytes === null ? null : (int) $row->bytes;
        } catch (\Throwable) {
            $name = DB::connection()->getDatabaseName();

            return is_file($name) ? (int) filesize($name) : null;
        }
    }

    protected function tableCount(): ?int
    {
        try {
            $row = DB::selectOne('SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = DATABASE()');

            return (int) ($row->tables ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function migrationCount(): ?int
    {
        try {
            return Schema::hasTable('migrations') ? (int) DB::table('migrations')->count() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function directorySize(string $path): int
    {
        $bytes = 0;

        foreach (File::allFiles($path) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }
}
