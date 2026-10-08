<?php

namespace App\Console\Commands;

use App\Domain\CashBank\Services\RecurringExpenseService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * The daily run behind §08-19.
 *
 * A scheduled expense that nobody runs is a schedule, not an expense, so this is
 * the line that turns due dates into documents — and it is deliberately the
 * thinnest possible command, because every rule lives in the service it calls:
 * the approval gate, the account the money leaves, and the refusal that leaves a
 * date where it was.
 *
 * It runs for every active company unless one is named, says out loud what it
 * generated and what it refused, and exits non-zero only when nothing could be
 * generated at all. A refusal is not a crash: it is the desk asking a person to
 * fix a switched-off category or a missing posting rule.
 */
class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'erp:cash:recurring-expenses
        {--company= : Company id (defaults to every active company)}
        {--limit=200 : How many due schedules to generate in one run}
        {--dry-run : Report what is due without generating anything}';

    protected $description = 'Generate the expenses whose schedules have come due (§08-19)';

    public function handle(RecurringExpenseService $recurring, TenantContext $context): int
    {
        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->where('is_active', true)->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No company found — nothing to generate.');

            return self::FAILURE;
        }

        $totals = ['generated' => 0, 'refused' => 0, 'skipped' => 0];
        $dryRun = (bool) $this->option('dry-run');

        foreach ($companies as $company) {
            // Settings, categories and expenses are all tenant-scoped, so a
            // scheduled run has to say which tenant it speaks for.
            $context->setCompany($company);
            $context->setBranch(null);
            $context->setUser(null);

            if ($dryRun) {
                $this->line(sprintf(
                    'Company %d (%s): %d schedule(s) due.',
                    $company->id,
                    $company->name,
                    $recurring->dueCount(),
                ));

                continue;
            }

            $result = $recurring->generateDue((int) $this->option('limit'));

            $totals['generated'] += $result['generated'];
            $totals['refused'] += $result['refused'];
            $totals['skipped'] += $result['skipped'];

            foreach ($result['details'] as $detail) {
                if (isset($detail['expense'])) {
                    $this->info("  generated {$detail['expense']} — {$detail['payee']} ({$detail['state']})");
                } elseif (isset($detail['refused'])) {
                    $this->warn("  refused {$detail['payee']}: {$detail['refused']}");
                } else {
                    $this->line("  skipped {$detail['payee']}: {$detail['skipped']}");
                }
            }
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d expense(s) generated, %d schedule(s) refused, %d skipped.',
            $totals['generated'],
            $totals['refused'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
