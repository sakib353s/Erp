<?php

namespace App\Console\Commands;

use App\Domain\Business\Services\UtilityService;
use App\Domain\Business\UtilityBill;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use Illuminate\Console\Command;

/**
 * §12-15 — the morning watch on the premises.
 *
 * A utility bill is the one creditor that arrives on a date whether or not
 * anybody opens the post, and the money it costs when it is late — a surcharge, a
 * reconnection fee, a branch without power — is the most avoidable money in the
 * company. So once a morning this looks at every unpaid bill and tells the desk
 * two things: what has already gone past its date, and what falls due inside the
 * fortnight.
 *
 * A digest, not one notification per bill, for the same reason the compliance
 * watch is one: a bell that rings eleven times is a bell people stop hearing.
 * Each digest is deduped per state, per company, per day, so running it by hand
 * after the scheduled run adds nothing.
 *
 * It changes nothing. Paying a bill is a decision with an account behind it, and
 * that belongs to a person, not to a cron job.
 */
class UtilityReminderCommand extends Command
{
    protected $signature = 'erp:business:utility-reminders
        {--company= : Company id (defaults to THE company)}
        {--permission=business.utilities.manage : Permission key whose holders are notified}
        {--days= : Horizon in days (defaults to the desk\'s due-soon window)}';

    protected $description = 'Notify the desk about overdue and soon-due utility bills';

    public function handle(
        UtilityService $utilities,
        NotificationRouter $router,
        NotificationCenter $notifications,
        TenantContext $context,
    ): int {
        $company = $this->option('company')
            ? Company::query()->find((int) $this->option('company'))
            : Company::current();

        if ($company === null) {
            $this->error('No company found — nothing to watch.');

            return self::FAILURE;
        }

        // Settings, the register and notification preferences are tenant-scoped,
        // so a scheduled run has to say which tenant it speaks for.
        $context->setCompany($company);

        $days = (int) ($this->option('days') ?: UtilityBill::DUE_SOON_DAYS);
        $days = max(1, $days);

        $permission = (string) $this->option('permission');
        $recipients = $router->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            $this->warn("Nobody holds {$permission}, so nothing was delivered. The bills are still on the register — nobody is being told about them.");

            return self::SUCCESS;
        }

        $lenses = $utilities->reminders($days);
        $delivered = 0;

        $digests = [
            [
                'key' => 'overdue',
                'rows' => $lenses['overdue'],
                'event' => 'business.utility.overdue',
                'title' => fn (int $count) => $count === 1
                    ? '1 utility bill is past its due date'
                    : "{$count} utility bills are past their due date",
                'body' => 'These have gone past the date on the bill and are still unpaid. A surcharge is cheaper than a disconnection: ',
                'priority' => 'high',
                'persistent' => true,
            ],
            [
                'key' => 'due',
                'rows' => $lenses['due'],
                'event' => 'business.utility.due',
                'title' => fn (int $count) => $count === 1
                    ? "1 utility bill falls due within {$days} days"
                    : "{$count} utility bills fall due within {$days} days",
                'body' => 'Still time, and this is when paying is cheap: ',
                'priority' => 'normal',
                'persistent' => false,
            ],
        ];

        foreach ($digests as $digest) {
            $rows = $digest['rows'];

            if ($rows->isEmpty()) {
                continue;
            }

            $count = $rows->count();
            $total = number_format((float) $rows->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2);
            $first = $rows->take(5)
                ->map(fn (UtilityBill $bill) => ($bill->provider?->name ?? 'provider').' '.$bill->bill_no.' ('.$bill->due_date?->format('d M').')')
                ->implode(', ');
            $more = $count > 5 ? ' …and '.number_format($count - 5).' more' : '';

            $delivered += $notifications->notifyMany(
                $recipients,
                $digest['event'],
                ($digest['title'])($count),
                $digest['body'].$first.$more.'. Total '.$total.'.',
                [
                    'priority' => $digest['priority'],
                    'action_url' => '/app/utility-bills/renewals',
                    'data' => [
                        'state' => $digest['key'],
                        'bills' => $count,
                        'total' => $total,
                        'horizon_days' => $days,
                    ],
                    // One digest per state per company per day.
                    'dedupe_key' => "business.utility.{$digest['key']}.{$company->id}.".now()->toDateString(),
                    'persistent' => $digest['persistent'],
                ],
            );
        }

        $this->info($delivered === 0
            ? 'Nothing to report: no overdue bills and nothing falling due in the next '.$days.' days.'
            : "{$delivered} utility reminder(s) delivered to ".$recipients->count().' person(s).');

        return self::SUCCESS;
    }
}
