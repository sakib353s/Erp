<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\SetupToken;
use Illuminate\Console\Command;

/**
 * Generates the one-time first-boot setup token (spec §49). The
 * plaintext is printed exactly once; only its SHA-256 hash is stored.
 */
class SetupTokenCommand extends Command
{
    protected $signature = 'erp:setup-token
        {--revoke : Invalidate any outstanding token without generating a new one}';

    protected $description = 'Generate (or revoke) the one-time first-boot setup token';

    public function handle(SetupToken $token): int
    {
        if ($this->option('revoke')) {
            $token->revoke();
            $this->info('Outstanding setup token revoked.');

            return self::SUCCESS;
        }

        if (app(\App\Domain\Foundation\Company::class)::current() !== null) {
            $this->error('Setup already completed on this instance — no token can be issued.');

            return self::FAILURE;
        }

        $plaintext = $token->generate();
        $ttl = (int) config('erp.setup.token_ttl_minutes', 60);

        $this->newLine();
        $this->line('  Setup token (shown ONCE, expires in '.$ttl.' minutes):');
        $this->newLine();
        $this->line('    <fg=green;options=bold>'.$plaintext.'</>');
        $this->newLine();
        $this->line('  Open /setup and paste this token together with your company');
        $this->line('  details and administrator account. The token is single-use;');
        $this->line('  only its SHA-256 hash is kept on disk.');

        return self::SUCCESS;
    }
}
