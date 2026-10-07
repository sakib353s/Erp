<?php

namespace App\Console\Commands;

use App\Domain\Workflow\Services\WorkflowEscalationService;
use Illuminate\Console\Command;

/**
 * Escalates overdue approval steps (section G). Schedule: every
 * config('erp.workflow.escalation_check_minutes') minutes.
 */
class WorkflowEscalateCommand extends Command
{
    protected $signature = 'erp:workflow:escalate';

    protected $description = 'Escalate approval steps that exceeded their SLA';

    public function handle(WorkflowEscalationService $service): int
    {
        $count = $service->escalateDue();

        $this->components->twoColumnDetail('Escalated steps', (string) $count);

        return self::SUCCESS;
    }
}
