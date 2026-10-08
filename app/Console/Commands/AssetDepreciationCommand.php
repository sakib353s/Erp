<?php

namespace App\Console\Commands;

use App\Domain\Business\AssetRegistry;
use App\Domain\Business\BusinessAsset;
use App\Domain\Business\Services\AssetService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * §12-14 — charging the month, without anybody remembering to.
 *
 * Wearing out is the only expense in the business that arrives without an
 * invoice, on a schedule, for years. If posting it depends on somebody
 * remembering, it is the first thing that quietly stops happening — and the
 * balance sheet carries assets at full cost until an auditor asks.
 *
 * So: once a month, for every capitalised asset whose month has ended, post the
 * charge — one journal entry per asset per month, debit depreciation expense and
 * credit accumulated depreciation — and then tell the people who keep the
 * register what it charged. It runs `--dry-run` just as happily, which is the
 * honest way to answer “what is the depreciation going to be this month?”
 * without touching the books.
 *
 * Deliberately **not** here: changing lives, salvage values or policies. Those
 * are decisions a person takes on the asset's own page, where the history, the
 * schedule and the reason are all visible.
 */
class AssetDepreciationCommand extends Command
{
    protected $signature = 'erp:business:asset-depreciation
        {--company= : Company id (defaults to THE company)}
        {--dry-run : Say what would be posted without posting it}
        {--permission=business.assets.manage : Permission key whose holders are notified}
        {--as-at= : Treat this date as today (for testing a period boundary)}';

    protected $description = 'Post this month\'s depreciation for every capitalised asset that is due';

    public function handle(
        AssetService $assets,
        NotificationRouter $router,
        NotificationCenter $notifications,
        TenantContext $context,
    ): int {
        $company = $this->option('company')
            ? Company::query()->find((int) $this->option('company'))
            : Company::current();

        if ($company === null) {
            $this->error('No company found — nothing to depreciate.');

            return self::FAILURE;
        }

        // Settings, accounts and currencies are tenant-scoped: a scheduled run
        // has to say which tenant it speaks for before it reads anything.
        $context->setCompany($company);

        $asAt = $this->option('as-at') !== null
            ? Carbon::parse((string) $this->option('as-at'))
            : now();

        if ($this->option('dry-run')) {
            $due = $assets->dueForDepreciation($company->id, $asAt);

            if ($due->isEmpty()) {
                $this->info('Nothing due as at '.$asAt->toDateString().': every depreciating asset is charged up to the last completed month.');

                return self::SUCCESS;
            }

            $total = 0.0;

            $this->table(
                ['Asset', 'Kind', 'Period', 'Charge', 'Book value after'],
                $due->map(function (BusinessAsset $asset) use ($asAt, &$total) {
                    $amount = round(min($asset->monthlyDepreciation(), $asset->remainingDepreciable()), 2);
                    $total += $amount;

                    return [
                        $asset->code.' — '.$asset->name,
                        $asset->categoryLabel(),
                        $assets->nextPeriodLabelFor($asset),
                        number_format($amount, 2),
                        number_format(max(0, (float) $asset->bookValue() - $amount), 2),
                    ];
                })->all(),
            );

            $this->info(sprintf(
                'Dry run: %d asset(s) would be charged ৳%s as at %s. Nothing was posted.',
                $due->count(),
                number_format($total, 2),
                $asAt->toDateString(),
            ));

            return self::SUCCESS;
        }

        try {
            $result = $assets->runDepreciation(null, $company->id, $asAt);
        } catch (\RuntimeException $e) {
            // The usual cause is a chart of accounts without 5270 or 1590. Saying
            // so beats a stack trace: it is a seeded account, not a bug.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['skipped'] as $note) {
            $this->warn($note);
        }

        if ($result['posted'] === []) {
            $this->info('Nothing was posted: '.($result['skipped'] === []
                ? 'no completed month is waiting to be charged.'
                : 'see the notes above.'));

            return self::SUCCESS;
        }

        $posted = $result['posted'];
        $periods = array_values(array_unique(array_map(fn (array $row) => $row['period'], $posted)));

        $this->info(sprintf(
            'Posted ৳%s of depreciation across %d asset(s), %d journal entr%s, covering %s — debit %s and credit %s.',
            number_format($result['total'], 2),
            count(array_unique(array_map(fn (array $row) => $row['asset']->id, $posted))),
            count($posted),
            count($posted) === 1 ? 'y' : 'ies',
            implode(', ', $periods),
            AssetRegistry::DEPRECIATION_EXPENSE_CODE,
            AssetRegistry::ACCUMULATED_DEPRECIATION_CODE,
        ));

        $this->notify($notifications, $router, $company, $result, $periods);

        return self::SUCCESS;
    }

    /**
     * Tell the people who keep the register. One digest per company per period —
     * the point is that somebody knows the books moved, not that they are told
     * once for every van.
     *
     * @param  array{posted: array<int, array{asset: BusinessAsset, amount: float, period: string}>, skipped: array<int, string>, total: float}  $result
     * @param  array<int, string>  $periods
     */
    private function notify(
        NotificationCenter $notifications,
        NotificationRouter $router,
        Company $company,
        array $result,
        array $periods,
    ): void {
        $permission = (string) $this->option('permission');
        $recipients = $router->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            $this->warn("Nobody holds {$permission}, so the run was not announced. It was posted — the ledger has it.");

            return;
        }

        $names = collect($result['posted'])
            ->map(fn (array $row) => $row['asset']->describe())
            ->unique()
            ->take(5)
            ->implode(', ');
        $more = count(array_unique(array_map(fn (array $row) => $row['asset']->id, $result['posted']))) > 5
            ? ' …and more'
            : '';

        $delivered = $notifications->notifyMany(
            $recipients,
            'business.assets.depreciated',
            'Depreciation posted: ৳'.number_format($result['total'], 2).' for '.implode(', ', $periods),
            sprintf(
                '%d journal entr%s posted — debit %s, credit %s, against %s%s. The register now carries the figures the ledger gave it.',
                count($result['posted']),
                count($result['posted']) === 1 ? 'y' : 'ies',
                AssetRegistry::DEPRECIATION_EXPENSE_CODE,
                AssetRegistry::ACCUMULATED_DEPRECIATION_CODE,
                $names,
                $more,
            ),
            [
                'priority' => 'normal',
                'action_url' => '/app/assets/depreciation',
                'data' => [
                    'periods' => implode(',', $periods),
                    'assets' => count(array_unique(array_map(fn (array $row) => $row['asset']->id, $result['posted']))),
                    'amount' => $result['total'],
                ],
                // One digest per company per period: running the command again by
                // hand after the scheduled run cannot nag anybody twice.
                'dedupe_key' => 'business.assets.depreciation.'.$company->id.'.'.implode('-', $periods),
                'persistent' => false,
            ],
        );

        $this->info($delivered.' notification(s) delivered.');
    }
}
