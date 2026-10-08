<?php

namespace App\Http\Controllers;

use App\Domain\Marketing\CampaignRecipient;
use App\Domain\Marketing\MarketingCampaign;
use App\Domain\Marketing\MarketingOptout;
use App\Domain\Marketing\MarketingRegistry;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notification\MessageTemplate;
use App\Http\Requests\StoreMarketingCampaignRequest;
use App\Http\Requests\StoreMarketingOptoutRequest;
use App\Http\Requests\StoreMarketingTemplateRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * §11 — the marketing desk.
 *
 * The menu has a channel group for SMS, one for email, one for WhatsApp and one
 * for push, plus the templates, the reports and the cost-against-revenue lens.
 * They are four ways of asking the same question — *who did we write to, what
 * did it cost, and what came back* — so they are one campaign table filtered by
 * `channel`, one template list filtered by `channel`, and one report, rather
 * than four copies of the same screens that would drift apart within a month.
 *
 * Everything that decides anything lives in `CampaignService`: the audience rule
 * expansion, the opt-out check, the transport honesty and the attribution. This
 * class reads the request, calls the engine, and puts the refusal on the page
 * when the engine says no.
 */
class MarketingCampaignController extends Controller
{
    public function __construct(protected CampaignService $campaigns) {}

    /** Every campaign, or one channel's. */
    public function index(Request $request): View
    {
        $channel = $this->channel($request->query('channel'));
        $preset = $this->preset($request->query('preset'));

        // A preset is a menu leaf's job made concrete: the win-back leaf opens the
        // “gone quiet” audience, the review leaf opens the recent buyers — with the
        // note that explains what the list is, rather than a second screen that
        // would be this one with a different title.
        $audience = $this->audience($request->query('audience')) ?? $preset['audience'] ?? null;

        $campaigns = $this->campaigns->visible($channel, $this->status($request->query('status')), $audience)
            ->withCount('recipients')
            ->paginate(20)
            ->withQueryString();

        return view('marketing.campaigns.index', [
            'campaigns' => $campaigns,
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
            'channel' => $channel,
            'audience' => $audience,
            'channels' => MarketingCampaign::CHANNELS,
            'audiences' => MarketingCampaign::AUDIENCES,
            'preset' => $preset,
            'overview' => $this->campaigns->overview(),
            'due' => MarketingCampaign::query()
                ->where('company_id', (int) $request->user()->company_id)
                ->due()
                ->orderBy('scheduled_at')
                ->get(),
        ]);
    }

    /** Write one down. */
    public function create(Request $request): View
    {
        // “Use it” from the template list: the body is filled in and the link kept.
        $fromTemplate = null;

        if ($request->filled('template')) {
            $fromTemplate = $this->campaigns->templates()
                ->firstWhere('id', (int) $request->query('template'));
        }

        return view('marketing.campaigns.form', [
            'campaign' => null,
            'fromTemplate' => $fromTemplate,
            'channels' => MarketingCampaign::CHANNELS,
            'audiences' => MarketingCampaign::AUDIENCES,
            'templates' => $this->campaigns->templates(),
            'customers' => $this->campaigns->choosable(),
            'preselected' => $fromTemplate?->channel ?? $this->channel($request->query('channel')) ?? MarketingCampaign::CHANNEL_SMS,
            'preselectedAudience' => $this->audience($request->query('audience')) ?? MarketingCampaign::AUDIENCE_ALL,
            'placeholders' => CampaignService::PLACEHOLDERS,
        ]);
    }

    public function store(StoreMarketingCampaignRequest $request): RedirectResponse
    {
        try {
            $campaign = $this->campaigns->save($request->validated(), null, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['body' => $error->getMessage()]);
        }

        return redirect()->route('marketing.campaigns.show', $campaign)->with(
            'status',
            "{$campaign->code} written down as a draft. Nothing goes out until somebody launches it."
        );
    }

    public function show(Request $request, MarketingCampaign $campaign): View
    {
        $this->authorizeCampaign($request, $campaign);

        $recipients = $campaign->recipients()
            ->with(['customer', 'message'])
            ->when($request->filled('state'), function ($query) use ($request) {
                $state = (string) $request->query('state');

                // The state lives on the outbox row, not in this table, so the
                // filter joins the two rather than keeping a second copy honest.
                if (in_array($state, [CampaignRecipient::SKIP_OPTOUT, CampaignRecipient::SKIP_NO_ADDRESS, CampaignRecipient::SKIP_BLACKLISTED], true)) {
                    $query->where('skip_reason', $state);
                } elseif ($state === 'handed') {
                    $query->whereNotNull('outbox_message_id');
                } else {
                    $query->whereHas('message', fn ($inner) => $inner->where('status', $state));
                }
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $performance = $this->campaigns->performance()['campaigns']
            ->firstWhere(fn (array $row) => $row['campaign']->id === $campaign->id);

        return view('marketing.campaigns.show', [
            'campaign' => $campaign->load(['template', 'creator']),
            'recipients' => $recipients,
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
            'customers' => $campaign->audience === MarketingCampaign::AUDIENCE_MANUAL ? $this->campaigns->choosable() : collect(),
            'performance' => $performance,
            // Who the rule matches *today* — shown before it is launched, so the
            // decision is made against the audience rather than in the dark.
            'previewCount' => $campaign->isEditable() ? $this->campaigns->audience($campaign)->count() : null,
        ]);
    }

    /** Correct a campaign that has not gone out yet. */
    public function edit(Request $request, MarketingCampaign $campaign): View
    {
        $this->authorizeCampaign($request, $campaign);

        return view('marketing.campaigns.form', [
            'campaign' => $campaign,
            'fromTemplate' => null,
            'channels' => MarketingCampaign::CHANNELS,
            'audiences' => MarketingCampaign::AUDIENCES,
            'templates' => $this->campaigns->templates(false),
            'customers' => $this->campaigns->choosable(),
            'preselected' => $campaign->channel,
            'preselectedAudience' => $campaign->audience,
            'placeholders' => CampaignService::PLACEHOLDERS,
        ]);
    }

    public function update(StoreMarketingCampaignRequest $request, MarketingCampaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        try {
            $this->campaigns->save($request->validated(), $campaign, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['body' => $error->getMessage()]);
        }

        return redirect()->route('marketing.campaigns.show', $campaign)->with('status', "{$campaign->code} updated.");
    }

    /** Send it. */
    public function launch(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $customerIds = array_map('intval', (array) $request->input('customer_ids', []));

        try {
            $result = $this->campaigns->launch($campaign, $request->user(), $customerIds);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['launch' => $error->getMessage()]);
        }

        $counts = $result['counts'];

        $summary = $counts['recipients'].' recipient(s): '.$counts['queued'].' queued, '.$counts['held'].' held'.(
            ($counts['skipped_opted_out'] + $counts['skipped_no_address'] + $counts['skipped_blacklisted']) > 0
                ? ', '.($counts['skipped_opted_out'] + $counts['skipped_no_address'] + $counts['skipped_blacklisted']).' skipped'
                : ''
        ).'.';

        return redirect()->route('marketing.campaigns.show', $campaign)->with('status', "{$campaign->code} launched — {$summary}");
    }

    public function schedule(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $data = $request->validate(['scheduled_at' => ['required', 'date']]);

        try {
            $this->campaigns->schedule($campaign, $request->user(), (string) $data['scheduled_at']);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['scheduled_at' => $error->getMessage()]);
        }

        return redirect()->route('marketing.campaigns.show', $campaign)
            ->with('status', "{$campaign->code} will go out on ".$campaign->scheduled_at?->format('d M Y \a\t H:i').'.');
    }

    public function unschedule(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        try {
            $this->campaigns->unschedule($campaign, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withErrors(['scheduled_at' => $error->getMessage()]);
        }

        return redirect()->route('marketing.campaigns.show', $campaign)->with('status', "{$campaign->code} is back in drafts — nothing will go out on its own.");
    }

    public function cancel(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $this->authorizeCampaign($request, $campaign);

        try {
            $this->campaigns->cancel($campaign, $request->user(), (string) $request->input('cancel_reason', ''));
        } catch (\RuntimeException $error) {
            return back()->withErrors(['cancel_reason' => $error->getMessage()]);
        }

        return redirect()->route('marketing.campaigns.index')->with('status', "{$campaign->code} cancelled.");
    }

    /* -------------------------------------------------------------- templates */

    public function templates(Request $request): View
    {
        return view('marketing.templates.index', [
            'templates' => $this->campaigns->templates(false),
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
            'channels' => MarketingCampaign::CHANNELS,
            'placeholders' => CampaignService::PLACEHOLDERS,
        ]);
    }

    public function createTemplate(Request $request): View
    {
        return view('marketing.templates.form', [
            'template' => null,
            'channels' => MarketingCampaign::CHANNELS,
            'placeholders' => CampaignService::PLACEHOLDERS,
        ]);
    }

    public function storeTemplate(StoreMarketingTemplateRequest $request): RedirectResponse
    {
        try {
            $template = $this->campaigns->saveTemplate($request->validated(), null, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['code' => $error->getMessage()]);
        }

        return redirect()->route('marketing.templates')->with('status', "Template {$template->code} saved.");
    }

    public function editTemplate(Request $request, MessageTemplate $template): View
    {
        abort_unless((int) $template->company_id === (int) $request->user()->company_id, 404);

        return view('marketing.templates.form', [
            'template' => $template,
            'channels' => MarketingCampaign::CHANNELS,
            'placeholders' => CampaignService::PLACEHOLDERS,
        ]);
    }

    public function updateTemplate(StoreMarketingTemplateRequest $request, MessageTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->company_id === (int) $request->user()->company_id, 404);

        try {
            $this->campaigns->saveTemplate($request->validated(), $template, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['code' => $error->getMessage()]);
        }

        return redirect()->route('marketing.templates')->with('status', "Template {$template->code} updated.");
    }

    /* --------------------------------------------------------------- opt-outs */

    public function optOuts(Request $request): View
    {
        return view('marketing.optouts', [
            'optouts' => $this->campaigns->optOuts($this->channel($request->query('channel')), $request->query('q')),
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
            'channels' => MarketingOptout::CHANNELS,
            'sources' => MarketingOptout::SOURCES,
            'channel' => $this->channel($request->query('channel')),
            'search' => (string) $request->query('q', ''),
            'overview' => $this->campaigns->overview(),
        ]);
    }

    public function storeOptOut(StoreMarketingOptoutRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->campaigns->optOut(
                (string) $data['channel'],
                (string) $data['contact'],
                $request->user(),
                $data['reason'] ?? null,
                (string) $data['source'],
                isset($data['customer_id']) ? \App\Domain\Masters\Customer::query()->find((int) $data['customer_id']) : null,
            );
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['contact' => $error->getMessage()]);
        }

        return redirect()->route('marketing.optouts')->with('status', 'Recorded — that contact will be skipped from now on.');
    }

    public function liftOptOut(Request $request, MarketingOptout $optout): RedirectResponse
    {
        abort_unless((int) $optout->company_id === (int) $request->user()->company_id, 404);

        try {
            $this->campaigns->liftOptOut($optout, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withErrors(['contact' => $error->getMessage()]);
        }

        return redirect()->route('marketing.optouts')->with('status', 'Lifted — they may be written to again, on that channel.');
    }

    /* --------------------------------------------------------------- reports */

    public function reports(Request $request): View
    {
        return view('marketing.reports', [
            'performance' => $this->campaigns->performance(),
            'overview' => $this->campaigns->overview(),
            'campaigns' => $this->campaigns->visible()->limit(50)->get(),
            'channels' => MarketingCampaign::CHANNELS,
        ]);
    }

    /* -------------------------------------------------------------- contacts */

    /**
     * The delivery lens: what the outbox actually holds for these campaigns.
     *
     * A “delivery report” that cannot say *held* is a delivery report that lies on
     * the days when nothing is configured — which is most of them. This one reads
     * the outbox directly, so the counts on top and the rows underneath are the
     * same fact.
     */
    public function deliveries(Request $request): View
    {
        $channel = $this->channel($request->query('channel'));
        $state = $this->deliveryState($request->query('state'));

        return view('marketing.deliveries', [
            'delivered' => $this->campaigns->deliveries($channel, $state, (string) $request->query('q', '')),
            'channels' => MarketingCampaign::CHANNELS,
            'channel' => $channel,
            'state' => $state,
            'search' => (string) $request->query('q', ''),
            'states' => [
                'queued' => 'Queued — a transport took it',
                'not_configured' => 'Held — no transport configured',
                'sent' => 'Sent — the transport confirmed it',
                'failed' => 'Failed — with the provider\'s own error',
            ],
        ]);
    }

    /**
     * Who a channel can actually reach today, and who it cannot — with the reason
     * on each row. The rules here are the dispatcher's rules, run early so the
     * answer is known before a campaign is written rather than after it goes out.
     */
    public function contacts(Request $request): View
    {
        $channel = $this->channel($request->query('channel')) ?? MarketingCampaign::CHANNEL_SMS;

        return view('marketing.contacts', [
            'reach' => $this->campaigns->contacts($channel),
            'channel' => $channel,
            'channels' => MarketingCampaign::CHANNELS,
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
        ]);
    }

    /* ------------------------------------------------------------ capabilities */

    /**
     * The leaves this application will not fake.
     *
     * Pixels, ad platforms, analytics properties, drip journeys, A/B tests, chat
     * inboxes and provider balances all need something this deployment does not
     * have — a third-party credential, a public storefront, or a public webhook.
     * Each one opens an honest page saying what it is, why it is not here, and
     * which real screen does the part of the job that can be done.
     */
    public function capability(Request $request, string $topic): View
    {
        $capability = MarketingRegistry::CAPABILITIES[$topic] ?? null;

        abort_if($capability === null, 404);

        return view('marketing.capability', [
            'topic' => $topic,
            'capability' => $capability,
            'others' => MarketingRegistry::CAPABILITIES,
            'canManage' => $request->user()->can('marketing.campaigns.manage'),
        ]);
    }

    /* ------------------------------------------------------------- internals */

    protected function authorizeCampaign(Request $request, MarketingCampaign $campaign): void
    {
        abort_unless((int) $campaign->company_id === (int) $request->user()->company_id, 404);
    }

    protected function channel(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return $value !== null && array_key_exists($value, MarketingCampaign::CHANNELS) ? $value : null;
    }

    /** @return array{title:string, audience:string, note:string, icon:string}|null */
    protected function preset(mixed $value): ?array
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return $value !== null ? (MarketingRegistry::PRESETS[$value] ?? null) : null;
    }

    protected function audience(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return $value !== null && array_key_exists($value, MarketingCampaign::AUDIENCES) ? $value : null;
    }

    protected function deliveryState(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return in_array($value, ['queued', 'not_configured', 'sent', 'failed', 'cancelled'], true) ? $value : null;
    }

    protected function status(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return in_array($value, MarketingCampaign::STATUSES, true) ? $value : null;
    }
}
