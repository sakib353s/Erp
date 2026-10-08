<?php

namespace App\Console\Commands;

use App\Domain\Business\BusinessRecord;
use App\Domain\Business\RecordsRegistry;
use App\Domain\Business\Services\BusinessRecordService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use Illuminate\Console\Command;

/**
 * §12-09 — the daily watch on the company's dates.
 *
 * A register nobody reads is a shelf. This is the line that makes it a register:
 * once a morning it looks at every licence, policy, contract, filing and duty and
 * tells the people who keep them two things — what has *lapsed* (already a
 * problem) and what lapses inside the next month (still cheap to fix).
 *
 * Deliberately a digest and not one notification per record, on the same reading
 * as the stock expiry alerts: a bell that rings forty times is a bell people
 * silence. Each digest is deduped per state, per company, per day, so running it
 * by hand after the scheduled run adds nothing.
 *
 * It changes nothing. Renewing, filing and retiring are decisions with dates and
 * reasons that belong to a person, not to a cron job — the command's job is to
 * make sure the person knows.
 */
class ComplianceAlertCommand extends Command
{
    protected $signature = 'erp:business:compliance-alerts
        {--company= : Company id (defaults to THE company)}
        {--permission=business.records.manage : Permission key whose holders are notified}
        {--days= : Horizon in days (defaults to the registry horizon)}';

    protected $description = 'Notify the desks about lapsed and soon-to-lapse business records';

    public function handle(
        BusinessRecordService $records,
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

        // Settings, the registers and notification preferences are all
        // tenant-scoped, so a scheduled run has to say which tenant it speaks for.
        $context->setCompany($company);

        $days = (int) ($this->option('days') ?: RecordsRegistry::HORIZON_DAYS);
        $days = max(1, $days);

        $permission = (string) $this->option('permission');
        $recipients = $router->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            $this->warn("Nobody holds {$permission}, so nothing was delivered. The registers are still being kept — nobody is being told.");

            return self::SUCCESS;
        }

        $lenses = $records->renewals($days);
        $delivered = 0;

        $digests = [
            [
                'key' => 'lapsed',
                'rows' => $lenses['lapsed'],
                'event' => 'business.compliance.lapsed',
                'title' => fn (int $count) => $count === 1
                    ? '1 record has lapsed'
                    : "{$count} records have lapsed",
                'body' => 'These are expired or past their deadline and are not retired. Renew them, file them, or retire them with a reason — a lapsed row nobody has decided about is the one that costs money at the wrong moment. ',
                'priority' => 'high',
                'persistent' => true,
            ],
            [
                'key' => 'soon',
                'rows' => $lenses['near'],
                'event' => 'business.compliance.expiring',
                'title' => fn (int $count) => $count === 1
                    ? '1 record lapses within 30 days'
                    : "{$count} records lapse within 30 days",
                'body' => 'Still time, and this is when renewing is cheap: the renewal, the filing receipt and the new number all go on the record. ',
                'priority' => 'normal',
                'persistent' => false,
            ],
        ];

        foreach ($digests as $digest) {
            /** @var \Illuminate\Support\Collection<int, BusinessRecord> $rows */
            $rows = $digest['rows'];

            if ($rows->isEmpty()) {
                continue;
            }

            $count = $rows->count();
            $first = $rows->take(5)
                ->map(fn (BusinessRecord $record) => $record->title.($record->trackedOn() ? ' ('.$record->trackedOn()->format('d M Y').')' : ''))
                ->implode(', ');
            $more = $count > 5 ? ' …and '.number_format($count - 5).' more' : '';

            $delivered += $notifications->notifyMany(
                $recipients,
                $digest['event'],
                ($digest['title'])($count),
                $digest['body'].$first.$more,
                [
                    'priority' => $digest['priority'],
                    'action_url' => '/app/compliance/renewals',
                    'data' => [
                        'state' => $digest['key'],
                        'records' => $count,
                        'horizon_days' => $days,
                    ],
                    // One digest per state per company per day.
                    'dedupe_key' => "business.compliance.{$digest['key']}.{$company->id}.".now()->toDateString(),
                    'persistent' => $digest['persistent'],
                ],
            );
        }

        $this->info($delivered === 0
            ? 'Nothing to report: no lapsed records and nothing lapsing in the next '.$days.' days.'
            : "{$delivered} compliance alert(s) delivered to ".$recipients->count().' person(s).');

        return self::SUCCESS;
    }
}
