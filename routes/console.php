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
 | The company's own dates (§12-09). A trade licence, an insurance policy, a
 | filing with the registrar and a monthly VAT return are the same animal — they
 | stop being true on a date — and the register is only worth keeping if somebody
 | is told before that happens. Two digests a morning: what has lapsed, and what
 | lapses inside the next month. It notifies and changes nothing: renewing,
 | filing and retiring are decisions a person takes, with a reason, on the record.
 |
 | Ten minutes after the stock expiry watch, so the two digests do not compete for
 | the same morning's attention.
 */
Schedule::command('erp:business:compliance-alerts')->dailyAt('06:50');

/*
 | The bell before the meeting (§12-11). Every quarter of an hour, because a
 | reminder that arrives an hour late is not a reminder — and once per meeting per
 | person, ever, because one that rings again five minutes later is one people
 | learn to ignore. Cancelled and held meetings are skipped; a meeting that was
 | moved has already told everybody its new time.
 */
Schedule::command('erp:business:meeting-reminders')->everyFifteenMinutes();

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
// §08-10 runs first: a bank charge is money already gone, so it is posted before
// the day's expenses are generated rather than after them.
Schedule::command('erp:cash:bank-charges')->dailyAt('06:10');
Schedule::command('erp:cash:recurring-expenses')->dailyAt('06:20');

/*
 | Depreciation (§12-14). Wearing out is the only expense that arrives without an
 | invoice, once a month, for years — which makes it the first thing that quietly
 | stops happening when it depends on somebody remembering.
 |
 | On the first morning of the month, so the month that just ended is charged
 | while it is still news, and dated the last day of that month rather than the
 | day the command ran. The accounts it posts to (5270 and 1590) are seeded; the
 | command says so plainly if they are missing rather than posting somewhere
 | approximate.
 */
Schedule::command('erp:business:asset-depreciation')->monthlyOn(1, '06:40');
