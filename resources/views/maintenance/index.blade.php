@extends('layouts.app')

@section('page_title', 'System maintenance')

@php
    use App\Domain\Operations\MaintenanceRun;

    $app = $report['application'];
    $db = $report['database'];
    $storage = $report['storage'];
    $services = $report['services'];
    $runtime = $report['runtime'];

    $problems = (array) ($lastCheck->details['problems'] ?? []);

    $tone = fn (string $t): string => match ($t) {
        'ok' => 'erp-chip-soft',
        'warn' => 'erp-chip-warn',
        default => 'erp-chip-outline',
    };
@endphp

@section('content')
    <x-ui.page-header
        eyebrow="Settings · System maintenance"
        title="System maintenance"
        subtitle="What this installation is, what may be done to it, and what will never be done to it automatically. Every operation on this page records what it did — how many files, how many bytes, which tables — so “the system felt slower after maintenance” is a question with an answer."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('maintenance.logs') }}">
                <i class="bi bi-journal-code" aria-hidden="true"></i> Error log
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('settings.index') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> Settings
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Database"
            value="{{ $db['size'] === null ? 'unknown' : MaintenanceRun::humanBytes($db['size']) }}"
            icon="bi-database"
            :hint="$db['driver'].($db['version'] ? ' '.$db['version'] : '').' · '.($db['tables'] ?? '?').' tables'" />
        <x-ui.kpi
            label="Storage free"
            value="{{ $storage['free'] === null ? 'unknown' : MaintenanceRun::humanBytes($storage['free']) }}"
            icon="bi-device-hdd"
            :hint="$storage['total'] === null ? $storage['path'] : MaintenanceRun::humanBytes($storage['total']).' total on '.$storage['path']" />
        <x-ui.kpi
            label="Pending jobs"
            value="{{ $services['jobs_pending'] === null ? 'n/a' : number_format($services['jobs_pending']) }}"
            icon="bi-hourglass-split"
            :hint="$services['queue'].' queue'.($services['jobs_oldest'] ? ' · oldest queued '.$services['jobs_oldest'] : '')" />
        <x-ui.kpi
            label="Failed jobs"
            value="{{ $services['jobs_failed'] === null ? 'n/a' : number_format($services['jobs_failed']) }}"
            icon="bi-exclamation-octagon"
            :hint="($services['jobs_failed'] ?? 0) > 0 ? 'A worker has been retrying something it cannot finish' : 'Nothing has exhausted its retries'" />
    </div>

    {{-- ------------------------------------------------------- claims (the system information block) --}}
    <section class="erp-card mb-3" id="system">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What this page does not claim</h2>
                <p class="erp-card-sub">
                    A system information page is only worth reading if it says “unknown” where it cannot see.
                    These are the questions an operator asks first, with the honest answer.
                </p>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="table erp-table">
                <thead>
                    <tr><th>Question</th><th>Answer</th><th>Why</th></tr>
                </thead>
                <tbody>
                    @foreach ($report['claims'] as $claim)
                        <tr>
                            <td class="erp-cell-strong">{{ $claim['label'] }}</td>
                            <td><span class="erp-chip {{ $tone($claim['tone']) }}">{{ $claim['verdict'] }}</span></td>
                            <td class="erp-td-muted">{{ $claim['why'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ------------------------------------------------------- system information --}}
    <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Application &amp; runtime</h2>
                    <span class="erp-chip erp-chip-outline">measured now</span>
                </header>
                <div class="p-3">
                    <dl class="erp-dl erp-dl-tight mb-0">
                        <dt>Application</dt><dd>{{ $app['name'] }} · {{ $app['environment'] }}{{ $app['debug'] ? ' · debug ON' : '' }}</dd>
                        <dt>Framework</dt><dd>Laravel {{ $app['laravel'] }} on PHP {{ $app['php'] }}</dd>
                        <dt>Host</dt><dd>{{ $app['os'] }} · {{ $app['server'] }}</dd>
                        <dt>Timezone &amp; locale</dt><dd>{{ $app['timezone'] }} · {{ $app['locale'] }} · amounts in {{ $app['currency'] }}</dd>
                        <dt>Memory limit</dt><dd>{{ $runtime['memory_limit'] }} (peak this request: {{ MaintenanceRun::humanBytes($runtime['memory_used']) }})</dd>
                        <dt>Request limits</dt><dd>{{ $runtime['max_execution_time'] }}s · upload {{ $runtime['upload_max_filesize'] }} · post {{ $runtime['post_max_size'] }}</dd>
                    </dl>

                    <p class="erp-filter-note mt-3 mb-1">Extensions this application uses:</p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach ($runtime['extensions'] as $name => $extension)
                            <span class="erp-chip {{ $extension['loaded'] ? 'erp-chip-soft' : 'erp-chip-warn' }}" title="{{ $extension['why'] }}">
                                {{ $name }}{{ $extension['loaded'] ? '' : ' missing' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Database, storage &amp; services</h2>
                </header>
                <div class="p-3">
                    <dl class="erp-dl erp-dl-tight">
                        <dt>Database</dt><dd>{{ $db['driver'] }} · {{ $db['name'] }} @ {{ $databaseHost }}{{ $db['version'] ? ' · '.$db['version'] : '' }}</dd>
                        <dt>Size</dt><dd>{{ $db['size'] === null ? 'not visible to this driver' : MaintenanceRun::humanBytes($db['size']) }} · {{ $db['tables'] ?? '?' }} tables · {{ $db['migrations'] ?? '?' }} migrations run</dd>
                        <dt>Cache &amp; sessions</dt><dd>{{ $services['cache'] }} cache · {{ $services['session'] }} sessions ({{ number_format((int) $services['sessions']) }} live)</dd>
                        <dt>Queue &amp; mail</dt><dd>{{ $services['queue'] }} queue · {{ $services['mail'] }} mailer · {{ $services['filesystem'] }} filesystem</dd>
                        <dt>Newest log line</dt><dd>{{ $storage['newest_log'] ?? 'no log file has been written yet' }}</dd>
                    </dl>

                    <p class="erp-filter-note mt-3 mb-1">Storage on disk:</p>
                    <table class="table erp-table mb-0">
                        <tbody>
                            @foreach ($storage['directories'] as $relative => $directory)
                                <tr>
                                    <td class="font-monospace erp-td-muted">storage/{{ $relative }}</td>
                                    <td class="erp-td-muted">{{ $directory['label'] }}</td>
                                    <td class="erp-td-num">{{ $directory['exists'] ? MaintenanceRun::humanBytes($directory['bytes']) : '—' }}</td>
                                    <td class="erp-td-num erp-td-muted">{{ $directory['exists'] ? number_format($directory['files']).' file(s)' : 'not present' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    {{-- ------------------------------------------------------- counts --}}
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What is in the database</h2>
                <p class="erp-card-sub">Counted in this request. After a restore, these are the first figures to compare — a count that came back as zero is how a silent failure is caught on the day it happens.</p>
            </div>
        </header>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                @foreach ($report['counts'] as $group => $tables)
                    <div class="col">
                        <p class="erp-filter-note mb-1">{{ $group }}</p>
                        <dl class="erp-dl erp-dl-tight mb-0">
                            @foreach ($tables as $table => $entry)
                                <dt>{{ $entry['label'] }}</dt>
                                <dd>{{ $entry['count'] === null ? '—' : number_format($entry['count']) }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ------------------------------------------------------- safe operations --}}
    <h2 class="erp-h2 mb-2" id="cache">Safe operations</h2>
    <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Clear cache</h2>
                    <span class="erp-chip erp-chip-soft">allowed self-heal</span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Clears the application cache and the compiled templates. Both are derived state: every byte of
                        them can be produced again from the database, which is exactly why this is safe.
                    </p>
                    <p class="erp-filter-note mb-3">
                        Does not touch: uploads and documents, backups, the audit trail, log files, or any table with a
                        business row in it.
                    </p>
                    @if ($perm('maintenance.cache'))
                        <form method="POST" action="{{ route('maintenance.cache.clear') }}" data-confirm="Clear the cache and compiled templates?">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="bi bi-eraser" aria-hidden="true"></i> Clear cache</button>
                        </form>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.cache</code>.</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100" id="sessions">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">End other sessions</h2>
                    <span class="erp-chip erp-chip-soft">allowed self-heal</span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Ends every session except the one you are using. This is the operation for a machine left signed
                        in at a counter, or for a person who has left the company.
                    </p>
                    <p class="erp-filter-note mb-3">
                        Your own session is deliberately kept: clearing sessions must not sign out the person doing it,
                        and it must not be a way to escape being the one who did it.
                    </p>
                    @if ($perm('maintenance.sessions'))
                        <form method="POST" action="{{ route('maintenance.sessions.clear') }}" data-confirm="End every session except your own?">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="bi bi-person-dash" aria-hidden="true"></i> End other sessions</button>
                        </form>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.sessions</code>.</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100" id="temp">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Remove temporary files</h2>
                    <span class="erp-chip erp-chip-soft">allowed self-heal</span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Sweeps the temporary directories below, skipping anything written in the last
                        {{ $tempMinAge }} hours because something may be writing it right now.
                    </p>
                    <table class="table erp-table mb-2">
                        <tbody>
                            @foreach ($sweep['sweep'] as $target)
                                <tr>
                                    <td class="font-monospace erp-td-muted">{{ $target['path'] }}</td>
                                    <td class="erp-td-num">{{ $target['exists'] ? MaintenanceRun::humanBytes($target['bytes']) : '—' }}</td>
                                    <td class="erp-td-num erp-td-muted">{{ $target['exists'] ? number_format($target['files']).' file(s)' : 'not present' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <details class="mb-3">
                        <summary class="erp-filter-note">Paths this sweep refuses to touch</summary>
                        <dl class="erp-dl erp-dl-tight mt-2 mb-0">
                            @foreach ($sweep['protected'] as $path => $why)
                                <dt class="font-monospace">storage/{{ $path }}</dt><dd>{{ $why }}</dd>
                            @endforeach
                        </dl>
                    </details>
                    @if ($perm('maintenance.temp'))
                        <form method="POST" action="{{ route('maintenance.temp.clear') }}" data-confirm="Remove temporary files older than {{ $tempMinAge }} hours?">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Remove temporary files</button>
                        </form>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.temp</code>.</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Rebuild the search index</h2>
                    <span class="erp-chip erp-chip-soft">allowed self-heal</span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Rebuilds global search entirely from the source tables — users, branches, warehouses, roles and
                        documents. The index is a projection: it holds no fact the source tables do not.
                    </p>
                    <p class="mb-3">
                        Current rows: <strong>{{ number_format($searchRows) }}</strong>
                    </p>
                    @if ($perm('maintenance.index'))
                        <form method="POST" action="{{ route('maintenance.rebuild-index') }}">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Rebuild search index</button>
                        </form>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.index</code>.</p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- ------------------------------------------------------- database --}}
    <h2 class="erp-h2 mb-2" id="database">Database</h2>
    <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Optimise &amp; check</h2>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        <strong>Optimise</strong> reclaims the space free inside the table files and reports how much
                        moved. <strong>Check</strong> asks every table whether it is sound — it reads, it never writes,
                        and its answer is what a repair is allowed to act on.
                    </p>
                    @if ($lastCheck)
                        <p class="erp-filter-note mb-3">
                            Last check {{ $lastCheck->created_at?->diffForHumans() }}:
                            <strong>{{ $lastCheck->summary }}</strong>
                        </p>
                    @else
                        <p class="erp-filter-note mb-3">
                            No integrity check has been run on this company yet.
                        </p>
                    @endif
                    @if ($perm('maintenance.database'))
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('maintenance.database.optimize') }}" data-confirm="Optimise all tables? This is slow on a large database.">
                                @csrf
                                <button class="btn btn-primary" type="submit"><i class="bi bi-speedometer2" aria-hidden="true"></i> Optimise</button>
                            </form>
                            <form method="POST" action="{{ route('maintenance.database.integrity') }}">
                                @csrf
                                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Run integrity check</button>
                            </form>
                        </div>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.database</code>.</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Repair</h2>
                    <span class="erp-chip {{ $problems === [] ? 'erp-chip-outline' : 'erp-chip-warn' }}">
                        {{ $problems === [] ? 'nothing to repair' : count($problems).' table(s) reported' }}
                    </span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Repair writes to table structure, so it stands on two things it will not assume: a check on
                        file, and a table that check named. It acts on nothing else — a “quick fix” that touches a table
                        nobody looked at is how data loss starts.
                    </p>

                    @if ($problems === [])
                        <p class="erp-filter-note mb-0">
                            Run an integrity check first. When it finds problems, they appear here with the tables that
                            reported them, and repair becomes available for exactly those.
                        </p>
                    @else
                        <table class="table erp-table mb-3">
                            <thead><tr><th>Table</th><th>What the check said</th></tr></thead>
                            <tbody>
                                @foreach ($problems as $table => $message)
                                    <tr>
                                        <td class="font-monospace">{{ $table }}</td>
                                        <td class="erp-td-muted">{{ $message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if ($perm('maintenance.repair'))
                            <form method="POST" action="{{ route('maintenance.database.repair') }}" data-confirm="Repair the checked tables? A fresh integrity check afterwards is the way to confirm it worked.">
                                @csrf
                                @foreach (array_keys($problems) as $table)
                                    <input type="hidden" name="tables[]" value="{{ $table }}">
                                @endforeach
                                <label class="erp-field-label">Type <code>{{ $confirmRepair }}</code> to confirm</label>
                                <input class="form-control mb-2" type="text" name="confirm" autocomplete="off" required>
                                <button class="btn btn-danger" type="submit"><i class="bi bi-tools" aria-hidden="true"></i> Repair {{ count($problems) }} table(s)</button>
                            </form>
                        @else
                            <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.repair</code>.</p>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- ------------------------------------------------------- self-healing --}}
    <section class="erp-card mb-3" id="heal">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Self-healing</h2>
                <p class="erp-card-sub">
                    The four safe operations above, in a fixed order, in one run. What is on this list is short on
                    purpose — and the second list is why.
                </p>
            </div>
            @if ($lastHeal)
                <span class="erp-chip erp-chip-outline">last run {{ $lastHeal->created_at?->diffForHumans() }}</span>
            @endif
        </header>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-xl-2 g-3">
                <div class="col">
                    <p class="erp-filter-note mb-2">Never performed automatically, whatever a heal is asked to do:</p>
                    <table class="table erp-table mb-0">
                        <tbody>
                            @foreach ($neverAutomatic as $key => $operation)
                                <tr>
                                    <td class="erp-cell-strong">{{ $operation['label'] }}</td>
                                    <td class="erp-td-muted">{{ $operation['why'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="col">
                    <p class="erp-filter-note mb-2">What a heal will run, in order:</p>
                    <ol class="mb-3">
                        <li>Clear cache and compiled templates</li>
                        <li>Remove temporary files older than {{ $tempMinAge }} hours</li>
                        <li>End other sessions</li>
                        <li>Rebuild the search index</li>
                    </ol>
                    @if ($perm('maintenance.heal'))
                        <form method="POST" action="{{ route('maintenance.self-heal') }}" data-confirm="Run the four safe operations now?">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="bi bi-heart-pulse" aria-hidden="true"></i> Run safe self-healing</button>
                        </form>
                    @else
                        <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.heal</code>.</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ------------------------------------------------------- reset --}}
    <section class="erp-card mb-3" id="reset">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Reset settings to their defaults</h2>
                <p class="erp-card-sub">Every group goes back to the declared default. Business data is not touched: invoices, payments, stock, the ledger and the audit trail are exactly where they were.</p>
            </div>
            <span class="erp-chip erp-chip-warn">irreversible</span>
        </header>
        <div class="p-3">
            <p class="text-body-secondary mb-2">
                Rows are deleted rather than rewritten with the default value. A row that says what the default already
                says is a decision nobody made — and it would hide the useful fact that this company has not chosen yet.
                The settings history and the audit trail are kept.
            </p>
            @if ($perm('maintenance.reset'))
                <form method="POST" action="{{ route('maintenance.settings.reset') }}" data-confirm="Reset every setting to its declared default?">
                    @csrf
                    <label class="erp-field-label">Type <code>{{ $confirmReset }}</code> to confirm</label>
                    <input class="form-control mb-2" type="text" name="confirm" autocomplete="off" required>
                    <button class="btn btn-danger" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset settings</button>
                </form>
            @else
                <p class="text-body-secondary mb-0">Your role does not hold <code>maintenance.reset</code>.</p>
            @endif
        </div>
    </section>

    {{-- ------------------------------------------------------- history --}}
    <section class="erp-table-shell mb-3" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">Maintenance history<span class="erp-chip erp-chip-outline">{{ $history->count() }} recent run(s)</span></h2>
        </div>
        <div class="erp-table-scroll">
            <table class="table erp-table">
                <thead>
                    <tr><th>When</th><th>Operation</th><th>Result</th><th>What it did</th><th class="erp-th-num">Freed</th><th>By</th></tr>
                </thead>
                <tbody>
                    @forelse ($history as $run)
                        <tr>
                            <td class="erp-td-muted">{{ $run->created_at?->format('d M Y H:i') }}</td>
                            <td class="erp-cell-strong">{{ $run->label() }}</td>
                            <td>
                                <span class="erp-chip {{ $run->status === 'ok' ? 'erp-chip-soft' : ($run->status === 'refused' ? 'erp-chip-outline' : 'erp-chip-warn') }}">
                                    {{ $run->status }}
                                </span>
                            </td>
                            <td class="erp-td-muted">{{ $run->summary }}</td>
                            <td class="erp-td-num">{{ $run->freed_bytes > 0 ? MaintenanceRun::humanBytes($run->freed_bytes) : '—' }}</td>
                            <td class="erp-td-muted">{{ $run->actor_label ?? 'system' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="erp-td-muted">
                                Nothing has been run from this desk yet. When something is, it appears here with the
                                figures it reported — the same record a later operator reads before pressing the same button.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="row row-cols-1 row-cols-xl-2 g-3">
        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Scheduled work (CLI)</h2>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        These run from cron, not from a browser: they are the operations that must happen whether or not
                        somebody is logged in. Their absence shows up as work that never happened rather than as an error
                        here, which is why the list is on this page.
                    </p>
                    <pre class="erp-pre mb-0">php artisan erp:search:rebuild
php artisan erp:chain-verify
php artisan erp:expiry-alerts
php artisan erp:generate-bank-charges
php artisan erp:generate-recurring-expenses
php artisan erp:expire-reservations
php artisan erp:workflow-escalate
php artisan menu:sync</pre>
                </div>
            </section>
        </div>

        <div class="col">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">What is not built yet</h2>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">
                        Said here rather than discovered later. Neither of these is offered as a button, because a
                        button that does nothing is worse than an empty space.
                    </p>
                    <dl class="erp-dl erp-dl-tight mb-0">
                        <dt>Backup &amp; restore (§15-19)</dt>
                        <dd>Not implemented. Take a database dump with your own tooling, and keep the restore steps written down outside the application — this desk will not report a backup as current until it can take and verify one.</dd>
                        <dt>Image derivatives (§15-29)</dt>
                        <dd>This build creates a document's image derivatives at upload time and stores them beside it. There is no separate thumbnail cache to regenerate, so the operation would be a button with nothing behind it.</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
