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
