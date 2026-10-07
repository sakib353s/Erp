<?php

namespace App\Domain\Reporting;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use Illuminate\Validation\ValidationException;

/**
 * Executes a saved report definition and records the run honestly:
 * exact row count, snapshot of produced rows, failure state, and an
 * audit event (AUD) for every run — scheduled or manual.
 */
class ReportRunService
{
    public function __construct(
        protected CustomReportBuilder $builder,
        protected AuditRecorder $audit,
    ) {}

    public function run(
        User $user,
        ReportDefinition $definition,
        array $filters = [],
        ?int $limit = null,
        ?ScheduledReport $scheduled = null,
    ): ReportRun {
        $started = now();
        $status = 'completed';
        $error = null;
        $result = null;

        try {
            $result = $this->builder->run(
                $user,
                $definition->source,
                $definition->columns,
                array_merge($definition->filters ?? [], $filters),
                $limit,
            );
        } catch (ValidationException $exception) {
            $status = 'failed';
            $error = json_encode($exception->errors(), JSON_UNESCAPED_SLASHES);
        }

        $run = ReportRun::query()->create([
            'company_id' => $definition->company_id,
            'report_definition_id' => $definition->id,
            'scheduled_report_id' => $scheduled?->id,
            'triggered_by' => $user->id,
            'status' => $status,
            'row_count' => $result['row_count'] ?? 0,
            'snapshot' => $result === null ? null : [
                'columns' => $result['columns'],
                'rows' => array_slice($result['rows'], 0, CustomReportBuilder::SNAPSHOT_ROWS),
            ],
            'error' => $error,
            'started_at' => $started,
            'finished_at' => now(),
        ]);

        $this->audit->record([
            'action' => 'sales.report_run',
            'entity_type' => 'report_run',
            'entity_id' => $run->id,
            'actor' => $user,
            'company_id' => $definition->company_id,
            'after' => [
                'definition_code' => $definition->code,
                'source' => $definition->source,
                'status' => $status,
                'row_count' => $run->row_count,
                'scheduled_report_id' => $scheduled?->id,
            ],
        ]);

        return $run;
    }
}
