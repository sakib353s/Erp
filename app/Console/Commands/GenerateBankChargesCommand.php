<?php

namespace App\Console\Commands;

use App\Domain\CashBank\Services\BankChargeService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * The daily run behind §08-10.
 *
 * A rule nobody runs is a note about a tariff rather than a charge, so this is
 * the line that turns due dates into postings — and it is deliberately the
 * thinnest possible command, because every rule lives in the service it calls:
 * which account the money leaves, which account it is booked to, what a
 * percentage is computed on, and the refusal that charges nothing when nothing
 * left the account.
 *
 * It runs for every active company unless one is named, says what it charged and
 * what it refused, and never exits non-zero for a refusal: a rule that could not
 * charge is the desk asking a person to look, not a crash.
 */
class GenerateBankChargesCommand extends Command
{
    protected $signature = 'erp:cash:bank-charges
        {--company= : Company id (defaults to every active company)}
        {--dry-run : Report what is due and what it would charge, without posting anything}';

    protected $description = 'Post the bank charges whose rules have come due (§08-10)';

    public function handle(BankChargeService $charges, TenantContext $context): int
    {
        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->where('is_active', true)->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No company found — nothing to charge for.');

            return self::FAILURE;
        }

        $totals = ['generated' => 0, 'refused' => 0, 'skipped' => 0];
        $dryRun = (bool) $this->option('dry-run');

        foreach ($companies as $company) {
            // Rules, accounts and charges are all tenant-scoped, so a scheduled
            // run has to say which tenant it speaks for.
            $context->setCompany($company);
            $context->setBranch(null);
            $context->setUser(null);

            if ($dryRun) {
                $this->line(sprintf(
                    'Company %d (%s): %d rule(s) due.',
                    $company->id,
                    $company->name,
                    $charges->dueCount(),
                ));
            }

            $result = $charges->generateDue(null, null, $dryRun);

            $totals['generated'] += $result['generated'];
            $totals['refused'] += $result['refused'];
            $totals['skipped'] += $result['skipped'];

            foreach ($result['details'] as $detail) {
                if (isset($detail['charge_no'])) {
                    $this->info("  charged {$detail['charge_no']} — {$detail['name']} ({$detail['amount']})");
                } elseif (isset($detail['would_charge'])) {
                    $this->line("  due {$detail['due_on']} — {$detail['name']} would charge {$detail['would_charge']}");
                } elseif (isset($detail['refused'])) {
                    $this->warn("  refused {$detail['name']}: {$detail['refused']}");
                } else {
                    $this->line("  skipped {$detail['name']}: {$detail['skipped']}");
                }
            }
        }

        if ($dryRun) {
            $this->info(sprintf('%d rule(s) are due; nothing was posted.', $totals['generated']));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d bank charge(s) posted, %d rule(s) refused, %d skipped.',
            $totals['generated'],
            $totals['refused'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
