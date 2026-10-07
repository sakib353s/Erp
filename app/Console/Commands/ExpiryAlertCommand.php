<?php

namespace App\Console\Commands;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Services\BatchService;
use App\Domain\Inventory\StockBatch;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Expiry alerts (§04-39).
 *
 * Three digests, one per state — expired, expiring inside the alert window, and
 * undated — sent to whoever may act on them. Deliberately a digest and not a
 * notification per batch: a list that re-fires every hour is a list people stop
 * reading, so each state is deduped to one alert per recipient per day.
 *
 * Nothing here writes stock. Expiring stock is a warning, and writing it off is
 * a decision somebody takes on the write-off screen — not a side effect of a date
 * passing and a cron job running.
 */
class ExpiryAlertCommand extends Command
{
    protected $signature = 'erp:inventory:expiry-alerts
        {--company= : Company id (defaults to the current company)}
        {--permission=inventory.batch.manage : Permission key whose holders are notified}
        {--days= : Alert window in days (defaults to the inventory setting)}';

    protected $description = 'Notify the desk about expired, expiring and undated batches';

    public function handle(
        BatchService $batches,
        SettingService $settings,
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

        // Settings, the register and notification preferences are all
        // tenant-scoped, so a scheduled run has to say which tenant it speaks for.
        $context->setCompany($company);

        $days = (int) ($this->option('days') ?: $settings->getInt('inventory', 'expiry_alert_days', 30));
        $days = max(1, $days);

        $permission = (string) $this->option('permission');
        $recipients = $router->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            $this->warn("Nobody holds {$permission}, so the alerts were not delivered anywhere.");

            return self::SUCCESS;
        }

        $delivered = 0;

        foreach ([StockBatch::STATE_EXPIRED, StockBatch::STATE_EXPIRING, StockBatch::STATE_UNDATED] as $type) {
            $rows = $batches->batches(['only_stocked' => true, 'state' => $type], $days)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $qty = round((float) $rows->sum('remaining_qty'), 4);
            $value = round((float) $rows->sum('remaining_value'), 4);
            $count = number_format($rows->count());
            $first = $rows->take(5)
                ->map(fn (StockBatch $batch) => trim($batch->batch_no.' ('.($batch->product?->sku ?? 'product removed').')'))
                ->implode(', ');
            $more = $rows->count() > 5 ? ' …and '.number_format($rows->count() - 5).' more' : '';

            $title = match ($type) {
                StockBatch::STATE_EXPIRED => "{$count} batch(es) are past their expiry date",
                StockBatch::STATE_EXPIRING => "{$count} batch(es) expire within {$days} days",
                default => "{$count} batch(es) arrived with no expiry date",
            };

            $body = match ($type) {
                StockBatch::STATE_EXPIRED => "They still hold {$qty} units worth {$value}. Expired stock is not sellable but it is still on the books: decide per batch whether to dispose of it, return it, or correct a misread date. {$first}{$more}",
                StockBatch::STATE_EXPIRING => "Worth moving first: {$qty} units at {$value}. Sell, transfer or return them while they are still worth full price. {$first}{$more}",
                default => "{$qty} units at {$value}, and nothing can warn about them because no date was recorded. Record the real dates from the receipt labels. {$first}{$more}",
            };

            $delivered += $notifications->notifyMany(
                $recipients,
                "inventory.batch.{$type}",
                $title,
                $body,
                [
                    'priority' => $type === StockBatch::STATE_EXPIRED ? 'high' : 'normal',
                    'action_url' => "/app/inventory/expiry?type={$type}&days={$days}",
                    'data' => [
                        'type' => $type,
                        'batches' => $rows->count(),
                        'qty' => $qty,
                        'value' => $value,
                    ],
                    // One digest per state per day per recipient.
                    'dedupe_key' => "inventory.expiry.{$type}.{$company->id}.".now()->toDateString(),
                    'persistent' => $type === StockBatch::STATE_EXPIRED,
                ],
            );
        }

        $this->info($delivered === 0
            ? 'Nothing to alert about: no expired, expiring or undated batch holds stock.'
            : "{$delivered} expiry alert(s) delivered to ".$recipients->count().' person(s).');

        return self::SUCCESS;
    }
}
