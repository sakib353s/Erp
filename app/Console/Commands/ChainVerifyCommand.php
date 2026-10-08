<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditChainVerifier;
use App\Domain\Foundation\Company;
use Illuminate\Console\Command;

/**
 * Verifies the tamper-evident audit hash chain (decision D14, Rule 16).
 * Exit code is non-zero when the chain is broken, so cron/CI can alert.
 */
class ChainVerifyCommand extends Command
{
    protected $signature = 'erp:chain-verify
        {--company= : Company id (defaults to THE company)}
        {--archives : Also verify every sealed period (§16-34)}';

    protected $description = 'Verify the audit hash chain end-to-end';

    public function handle(AuditChainVerifier $verifier): int
    {
        $companyId = (int) ($this->option('company') ?: Company::current()?->id);

        if ($companyId === 0) {
            $this->error('No company found — nothing to verify.');

            return self::FAILURE;
        }

        $result = $verifier->verify($companyId);

        if ($result['ok']) {
            $this->info("Chain OK — {$result['checked']} events verified for company {$companyId}.");

            return $this->verifyArchives($verifier, $companyId, $result['checked']);
        }

        $this->error(sprintf(
            'Chain BROKEN for company %d after %d valid events: %s at seq %s',
            $companyId,
            $result['checked'],
            $result['reason'],
            $result['broken_at'] ?? '?',
        ));

        return self::FAILURE;
    }

    /**
     * §16-34 — a sealed period is only worth having if somebody re-reads it.
     * The seals are checked here, after the chain itself is known good, so a
     * failure can only mean "the segment no longer matches its seal".
     */
    protected function verifyArchives(AuditChainVerifier $verifier, int $companyId, int $checked): int
    {
        if (! $this->option('archives')) {
            return self::SUCCESS;
        }

        $result = $verifier->verifyArchives($companyId);

        if ($result['archives'] === 0) {
            $this->line('No sealed periods yet — nothing to re-verify.');

            return self::SUCCESS;
        }

        if ($result['ok']) {
            $this->info("Seals OK — {$result['archives']} sealed period(s), {$result['checked']} events re-verified ({$checked} chain events).");

            return self::SUCCESS;
        }

        foreach ($result['failures'] as $failure) {
            $this->error(sprintf(
                'Seal BROKEN for period %s (seq %d–%d): %s at seq %s',
                $failure['period'],
                $failure['seq_from'],
                $failure['seq_to'],
                $failure['reason'],
                $failure['broken_at'] ?? '?',
            ));
        }

        return self::FAILURE;
    }
}
