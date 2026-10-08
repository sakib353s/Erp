<?php

namespace App\Console\Commands;

use App\Domain\Business\Services\VisitorService;
use App\Domain\Business\VisitorVisit;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use Illuminate\Console\Command;

/**
 * §12-16 — the gate's morning watch.
 *
 * Two things are worth a bell at the start of the day, and they are not the
 * same size. The quiet one is the diary: who is expected today, so the front
 * desk knows before the first visitor is standing in front of it. The loud one
 * is a visitor row that is still open from an earlier day — somebody was checked
 * in and never checked out, which in a building with stock and cash in it is the
 * one entry that must never be left ambiguous.
 *
 * A digest, not a notification per visitor, for the same reason the compliance
 * watch is one: a bell that rings eleven times is a bell people stop hearing.
 * Each digest is deduped per state, per company, per day.
 *
 * It changes nothing. Admitting somebody or closing a row is a decision with a
 * name behind it, and that belongs to a person at the desk.
 */
class VisitorWatchCommand extends Command
{
    protected $signature = 'erp:business:visitor-watch
        {--company= : Company id (defaults to THE company)}
        {--permission=business.visitors.manage : Permission key whose holders are notified}';

    protected $description = 'Notify the gate about the visitors expected today and any row left open';

    public function handle(
        VisitorService $visitors,
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

        // The register and notification preferences are tenant-scoped, so a
        // scheduled run has to say which tenant it speaks for.
        $context->setCompany($company);

        $permission = (string) $this->option('permission');
        $recipients = $router->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            $this->warn("Nobody holds {$permission}, so nothing was delivered. The gate diary is still on the desk — nobody is being told about it.");

            return self::SUCCESS;
        }

        $expected = $visitors->today()['expected'];
        $forgotten = $visitors->forgotten();
        $delivered = 0;

        if ($expected->isNotEmpty()) {
            $names = $expected
                ->take(6)
                ->map(fn (VisitorVisit $visit) => ($visit->visitor?->name ?? 'visitor').' ('.$visit->scheduled_for?->format('H:i').')')
                ->implode(', ');
            $more = $expected->count() > 6 ? ' …and '.number_format($expected->count() - 6).' more' : '';

            $delivered += $notifications->notifyMany(
                $recipients,
                'business.visitor.expected_today',
                $expected->count() === 1
                    ? '1 visitor is expected today'
                    : $expected->count().' visitors are expected today',
                'The gate diary for today: '.$names.$more.'.',
                [
                    'priority' => 'normal',
                    'action_url' => '/app/visitors/expected',
                    'data' => ['state' => 'expected', 'visitors' => $expected->count()],
                    'dedupe_key' => "business.visitor.expected.{$company->id}.".now()->toDateString(),
                    'persistent' => false,
                ],
            );
        }

        if ($forgotten->isNotEmpty()) {
            $names = $forgotten
                ->take(5)
                ->map(fn (VisitorVisit $visit) => ($visit->visitor?->name ?? 'visitor').' (badge '.$visit->badge_no.', since '.$visit->checked_in_at?->format('d M H:i').')')
                ->implode(', ');
            $more = $forgotten->count() > 5 ? ' …and '.number_format($forgotten->count() - 5).' more' : '';

            $delivered += $notifications->notifyMany(
                $recipients,
                'business.visitor.open_stay',
                $forgotten->count() === 1
                    ? '1 visitor row is still open from an earlier day'
                    : $forgotten->count().' visitor rows are still open from earlier days',
                'Checked in and never checked out: '.$names.$more.'. Close them on the log — an entry nobody can read is worse than no entry.',
                [
                    'priority' => 'high',
                    'action_url' => '/app/visitors?status=inside',
                    'data' => ['state' => 'open_stay', 'visitors' => $forgotten->count()],
                    'dedupe_key' => "business.visitor.open.{$company->id}.".now()->toDateString(),
                    'persistent' => true,
                ],
            );
        }

        $this->info($delivered === 0
            ? 'Nothing to report: nobody expected today and no row left open.'
            : "{$delivered} visitor notice(s) delivered to ".$recipients->count().' person(s).');

        return self::SUCCESS;
    }
}
