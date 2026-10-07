<?php

namespace App\Console\Commands;

use App\Domain\Foundation\Company;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * The reservation expiry job (§04-34). Overdue holds give their quantity back to
 * availability; the same sweep runs from the reservations screen, and every
 * release is audited. Safe to run as often as you like — only past-due holds
 * with an ACTIVE status are touched.
 */
class ExpireReservationsCommand extends Command
{
    protected $signature = 'erp:reservations:expire {--company= : Company id (defaults to THE company)}';

    protected $description = 'Release active stock reservations whose deadline has passed';

    public function handle(ReservationService $reservations): int
    {
        $companyId = (int) ($this->option('company') ?: Company::current()?->id);

        if ($companyId === 0) {
            $this->error('No company found — nothing to expire.');

            return self::FAILURE;
        }

        $count = $reservations->expireDue($companyId);

        $this->info($count === 0
            ? 'No reservation has passed its deadline.'
            : "{$count} overdue reservation(s) expired; the quantity is available again.");

        return self::SUCCESS;
    }
}
