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
    protected $signature = 'erp:chain-verify {--company= : Company id (defaults to THE company)}';

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

            return self::SUCCESS;
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
}
