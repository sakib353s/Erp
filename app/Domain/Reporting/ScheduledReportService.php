<?php

namespace App\Domain\Reporting;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Schedules a saved report (AUD on creation) and executes due schedules
 * through ReportRunService — each execution produces an audited
 * report_runs record and advances next_run_at by its frequency.
 */
class ScheduledReportService
{
    public function __construct(
        protected ReportRunService $runs,
        protected AuditRecorder $audit,
    ) {}

    public function schedule(
        User $user,
        ReportDefinition $definition,
        string $name,
        string $frequency,
    ): ScheduledReport {
        if (! in_array($frequency, ScheduledReport::FREQUENCIES, true)) {
            throw ValidationException::withMessages([
                'frequency' => 'Frequency must be daily, weekly or monthly.',
            ]);
        }

        $row = ScheduledReport::query()->create([
            'company_id' => $definition->company_id,
            'report_definition_id' => $definition->id,
            'name' => $name,
            'frequency' => $frequency,
            'next_run_at' => now(),
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $this->audit->record([
            'action' => 'sales.report_scheduled',
            'entity_type' => 'scheduled_report',
            'entity_id' => $row->id,
            'actor' => $user,
            'company_id' => $definition->company_id,
            'after' => [
                'definition_code' => $definition->code,
                'frequency' => $frequency,
                'next_run_at' => $row->next_run_at->toIso8601String(),
            ],
        ]);

        return $row;
    }

    /** Execute every due, active schedule. Returns how many ran. */
    public function runDue(): int
    {
        $executed = 0;

        ScheduledReport::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->with(['reportDefinition', 'creator'])
            ->chunkById(50, function ($rows) use (&$executed): void {
                foreach ($rows as $row) {
                    $definition = $row->reportDefinition;
                    $user = $row->creator;

                    if ($definition === null || $user === null) {
                        $row->update(['is_active' => false]);

                        continue;
                    }

                    $this->runs->run($user, $definition, [], null, $row);

                    $row->forceFill([
                        'last_run_at' => now(),
                        'next_run_at' => $this->nextRunAt($row->frequency),
                    ])->save();

                    $executed++;
                }
            });

        return $executed;
    }

    protected function nextRunAt(string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        };
    }
}
