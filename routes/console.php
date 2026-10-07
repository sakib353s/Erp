<?php

use App\Domain\Reporting\ScheduledReportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:run-due', function () {
    $count = app(ScheduledReportService::class)->runDue();

    $this->info("Executed {$count} due scheduled report run(s).");
})->purpose('Execute due scheduled custom report runs');
