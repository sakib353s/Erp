<?php

use App\Domain\Reporting\ScheduledReportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:run-due', function () {
    $count = app(ScheduledReportService::class)->runDue();

    $this->info("Executed {$count} due scheduled report run(s).");
})->purpose('Execute due scheduled custom report runs');

/*
 | A daily watch on dated stock (§04-39). Stock with a date on it warns nobody
 | until the watch is actually run: a batch that crosses its date unnoticed is the
 | failure this line exists to prevent. It reads, it notifies, and it never writes
 | stock off — disposal is a decision a person takes, not a side effect of a date.
 |
 | The digest is deduped per state, per recipient, per day, so running it by hand
 | after the scheduled run is a no-op rather than a second bell.
 */
Schedule::command('erp:inventory:expiry-alerts')->dailyAt('06:40');

/*
 | The standing expenses (§08-19). Rent, salaries and the internet line do not
 | need discovering, and a desk that retypes them every month eventually forgets
 | one. The run generates what is due through the ordinary expense path, so the
 | approval limit applies to a generated bill exactly as it applies to a typed
 | one, and a refusal leaves the date where it was rather than skipping a month.
 |
 | Early, before the working day: whatever needs a signature should be waiting on
 | somebody's desk when they sit down, not appearing at lunchtime.
 */
Schedule::command('erp:cash:recurring-expenses')->dailyAt('06:20');
