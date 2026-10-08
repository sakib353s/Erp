<?php

namespace App\Console\Commands;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Marketing\MarketingCampaign;
use App\Domain\Marketing\Services\CampaignService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * §11 — the dispatcher: campaigns that were put on the clock, sent on the day.
 *
 * The point of scheduling a campaign ahead of time is that nobody has to remember
 * it — and the point of the *audience rule* is that the people it goes to are
 * worked out on the day rather than on the afternoon the campaign was written.
 * That is exactly what this command does: it finds every scheduled campaign whose
 * time has come, expands its audience as it is now, honours the opt-out register,
 * and hands the messages to the outbox.
 *
 * It cannot mark anything sent. A campaign launched here writes the same rows a
 * hand launch writes — recipients, skipped rows with their reasons, and outbox
 * messages that say `queued` when a real transport exists for the channel and
 * `not_configured` when none does. WhatsApp and push campaigns will be held, and
 * the desk will say so.
 *
 * A campaign whose audience matches nobody at the moment it fires is *left
 * scheduled* and reported as skipped: launching it with no recipients would mark
 * a send that never happened, and the report would carry that lie forever. The
 * run is deliberately not an error in that case — it is the honest outcome.
 *
 * Runs as the system, so the audit trail says so (`actor_type: system`) rather
 * than pinning it on whoever happened to be logged in.
 */
class MarketingDispatchCommand extends Command
{
    protected $signature = 'erp:marketing:dispatch
        {--company= : Company id (defaults to the current company)}
        {--at= : Treat this moment as “now” (ISO-8601) — for a back-dated or forecast run}
        {--dry-run : List what would go out, and change nothing}';

    protected $description = 'Launch marketing campaigns whose scheduled time has come, with the audience as it is on the day';

    public function handle(CampaignService $campaigns, TenantContext $context): int
    {
        $company = $this->option('company')
            ? Company::query()->find((int) $this->option('company'))
            : Company::current();

        if ($company === null) {
            $this->error('No company found — nothing to dispatch.');

            return self::FAILURE;
        }

        // Campaigns, opt-outs and templates are tenant-scoped, so a scheduled run
        // has to say which tenant it speaks for.
        $context->setCompany($company);

        $now = $this->option('at') !== null ? Carbon::parse((string) $this->option('at')) : Carbon::now();

        $due = MarketingCampaign::query()
            ->where('company_id', $company->id)
            ->due($now)
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing on the clock: no campaign is due.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($due as $campaign) {
                $audience = $campaigns->audience($campaign)->count();

                $this->line(sprintf(
                    'would launch %s (%s, %s) to %d customer(s) — scheduled for %s',
                    $campaign->code,
                    $campaign->channel,
                    $campaign->audience,
                    $audience,
                    $campaign->scheduled_at?->toDateTimeString() ?? '—',
                ));
            }

            $this->info($due->count().' campaign(s) would go out. Nothing was changed.');

            return self::SUCCESS;
        }

        $launched = 0;
        $held = 0;

        foreach ($campaigns->dispatchDue($now) as $result) {
            $campaign = $result['campaign'];

            if ($result['error'] !== null) {
                $held++;
                $this->warn(sprintf('%s stayed scheduled: %s', $campaign->code, $result['error']));

                continue;
            }

            $counts = $result['counts'];
            $launched++;

            $this->line(sprintf(
                '%s went out on %s — %d recipient(s): %d queued, %d held, %d skipped.',
                $campaign->code,
                $campaign->channel,
                $counts['recipients'],
                $counts['queued'],
                $counts['held'],
                $counts['skipped_opted_out'] + $counts['skipped_no_address'] + $counts['skipped_blacklisted'],
            ));
        }

        $this->info($launched.' campaign(s) dispatched'.($held > 0 ? ", {$held} left on the clock" : '').'. No message was marked sent — the outbox decides that.');

        return self::SUCCESS;
    }
}
