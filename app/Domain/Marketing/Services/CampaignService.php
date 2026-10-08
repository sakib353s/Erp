<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Marketing\CampaignRecipient;
use App\Domain\Marketing\MarketingCampaign;
use App\Domain\Marketing\MarketingOptout;
use App\Domain\Marketing\MarketingRegistry;
use App\Domain\Masters\Customer;
use App\Domain\Masters\SmsProvider;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §11 — the marketing desk's engine.
 *
 * Four things are being kept honest here, and the first one is the reason the
 * other three matter.
 *
 * **Nothing claims a delivery it did not make.** A campaign hands messages to
 * the shared outbox, and the outbox only ever says `queued` when a real
 * transport exists for that channel and `not_configured` when it does not.
 * WhatsApp and Push have no transport registry at all in this application, so
 * their messages are held and the desk says so; there is no code path here that
 * writes `sent` — that belongs to whatever eventually talks to a provider.
 * Recipients who were skipped are rows too, with the reason on them, because
 * “we did not write to this person” and “we wrote and nothing came back” are
 * different facts and a marketing report that confuses them is worthless.
 *
 * **An opt-out is honoured by the machine, not by a memory.** The check runs
 * before anything is queued, keyed on the contact itself, and a channel of
 * `all` blocks every channel — which is what somebody who writes “stop
 * everything” has actually asked for.
 *
 * **The audience is expanded on the day.** The rule is stored and re-read at
 * launch, so “everybody who bought in the last 90 days” means that when the
 * campaign goes out and not when somebody typed it.
 *
 * **Revenue is the recipients' orders, nothing else.** Attribution is a window
 * after the launch plus the invoices those customers actually have in it —
 * which is a figure a person can check by opening the customers, unlike an
 * “engagement” score.
 */
class CampaignService
{
    public const CODE_PREFIX = 'MC-';

    /** The channels this desk runs campaigns on. */
    public const CHANNELS = [
        MarketingCampaign::CHANNEL_SMS,
        MarketingCampaign::CHANNEL_EMAIL,
        MarketingCampaign::CHANNEL_WHATSAPP,
        MarketingCampaign::CHANNEL_PUSH,
    ];

    /** Placeholders a body may use; anything else is left exactly as typed. */
    public const PLACEHOLDERS = ['{customer}', '{company}', '{month}', '{date}', '{channel}'];

    public function __construct(
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for the marketing desk.'));
    }

    /* ------------------------------------------------------------- the templates */

    /**
     * The bodies this company may send: its own, plus the structural system rows
     * the messaging slices already use.
     *
     * @return Collection<int, MessageTemplate>
     */
    public function templates(bool $activeOnly = true): Collection
    {
        $this->materialiseTemplates();

        return MessageTemplate::query()
            ->whereIn('channel', self::CHANNELS)
            ->where(function (Builder $query) {
                $query->whereNull('company_id')->orWhere('company_id', $this->companyId());
            })
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('channel')
            ->orderBy('name')
            ->get();
    }

    /**
     * Give a company that has never opened the desk something to send — once,
     * and never over an edited body.
     */
    public function materialiseTemplates(): int
    {
        $companyId = $this->companyId();

        $existing = MessageTemplate::query()->where('company_id', $companyId)->exists();

        if ($existing) {
            return 0;
        }

        $created = 0;

        foreach (MarketingRegistry::TEMPLATES as $row) {
            MessageTemplate::query()->create([
                'company_id' => $companyId,
                'code' => $row['code'],
                'channel' => $row['channel'],
                'name' => $row['name'],
                'subject' => $row['subject'],
                'body' => $row['body'],
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }

    /** @param array{code:string, channel:string, name:string, subject?:string|null, body:string, is_active?:bool} $data */
    public function saveTemplate(array $data, ?MessageTemplate $template, ?User $actor): MessageTemplate
    {
        $companyId = $this->companyId();
        $channel = (string) $data['channel'];

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('A marketing body belongs to one of the four channels.');
        }

        $body = trim((string) $data['body']);

        if ($body === '') {
            throw new RuntimeException('A template with no body is a template nobody can send.');
        }

        $code = strtoupper(trim((string) $data['code']));

        if ($code === '') {
            throw new RuntimeException('Give the template a code — it is how the desk finds it again.');
        }

        if ($template !== null && (int) $template->company_id !== $companyId) {
            throw new RuntimeException('That template belongs to another company.');
        }

        if ($template === null && MessageTemplate::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('channel', $channel)
            ->exists()) {
            throw new RuntimeException("Template {$code} already exists on the {$channel} channel.");
        }

        $before = $template?->only(['code', 'name', 'channel', 'body', 'is_active']);

        $attributes = [
            'company_id' => $companyId,
            'code' => $code,
            'channel' => $channel,
            'name' => trim((string) $data['name']),
            'subject' => $this->blankToNull($data['subject'] ?? null),
            'body' => $body,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $template = $template ?? new MessageTemplate;
        $template->fill($attributes)->save();

        $this->audit->record([
            'action' => $before === null ? 'marketing.template_created' : 'marketing.template_updated',
            'entity_type' => 'message_template',
            'entity_id' => $template->id,
            'actor_id' => $actor?->id,
            'before' => $before,
            'after' => $template->only(['code', 'name', 'channel', 'is_active']),
        ]);

        return $template;
    }

    /* ------------------------------------------------------------ the campaigns */

    /** Every campaign this company is running, newest first. */
    public function visible(?string $channel = null, ?string $status = null, ?string $audience = null): Builder
    {
        return MarketingCampaign::query()
            ->where('company_id', $this->companyId())
            ->when($channel !== null, fn (Builder $query) => $query->where('channel', $channel))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($audience !== null, fn (Builder $query) => $query->where('audience', $audience))
            ->with(['template', 'creator'])
            ->orderByDesc('id');
    }

    /**
     * Write a campaign down. A launched campaign is history and is not editable:
     * its recipients and the bodies they were sent are already rows.
     *
     * @param  array{channel:string, name:string, objective?:string|null, template_id?:int|null,
     *               subject?:string|null, body:string, audience:string, audience_days?:int|null,
     *               cost_per_message?:string|float, attribution_days?:int|null, notes?:string|null,
     *               scheduled_at?:string|null}  $data
     */
    public function save(array $data, ?MarketingCampaign $campaign, ?User $actor): MarketingCampaign
    {
        $companyId = $this->companyId();
        $channel = (string) $data['channel'];
        $audience = (string) $data['audience'];

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('A campaign runs on one of the four channels.');
        }

        if (! array_key_exists($audience, MarketingCampaign::AUDIENCES)) {
            throw new RuntimeException('Pick an audience rule — a campaign with no audience is a draft nobody can launch.');
        }

        $name = trim((string) $data['name']);

        if ($name === '') {
            throw new RuntimeException('A campaign needs a name — the report has to say which one this was.');
        }

        $body = trim((string) $data['body']);

        if ($body === '') {
            throw new RuntimeException('A campaign needs a message before it can be launched.');
        }

        if ($campaign !== null) {
            if ((int) $campaign->company_id !== $companyId) {
                throw new RuntimeException('That campaign belongs to another company.');
            }

            if (! $campaign->isEditable()) {
                throw new RuntimeException(
                    $campaign->isLaunched()
                        ? 'This campaign has already gone out — what it sent is history now. Start a new one.'
                        : 'A cancelled campaign is a decision that was made; it is not edited back to life.'
                );
            }
        }

        $templateId = $data['template_id'] ?? null;

        if ($templateId !== null && $templateId !== '') {
            $template = MessageTemplate::query()
                ->where('channel', $channel)
                ->whereKey((int) $templateId)
                ->where(function (Builder $query) use ($companyId) {
                    $query->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->first();

            if ($template === null) {
                throw new RuntimeException('That template does not belong to this channel.');
            }
        } else {
            $templateId = null;
        }

        $subject = $this->blankToNull($data['subject'] ?? null);

        if ($channel === MarketingCampaign::CHANNEL_EMAIL && $subject === null) {
            throw new RuntimeException('An email campaign needs a subject line — it is the only part some people read.');
        }

        if ($channel !== MarketingCampaign::CHANNEL_EMAIL) {
            $subject = null; // a subject on an SMS is a field nobody sees
        }

        $before = $campaign?->only(['name', 'channel', 'body', 'audience', 'cost_per_message', 'scheduled_at']);

        $campaign = $campaign ?? new MarketingCampaign(['company_id' => $companyId, 'created_by' => $actor?->id]);

        $campaign->fill([
            'company_id' => $companyId,
            'code' => $campaign->code ?: $this->nextCode(),
            'channel' => $channel,
            'name' => $name,
            'objective' => $this->blankToNull($data['objective'] ?? null),
            'template_id' => $templateId,
            'subject' => $subject,
            'body' => $body,
            'audience' => $audience,
            'audience_days' => in_array($audience, [MarketingCampaign::AUDIENCE_RECENT, MarketingCampaign::AUDIENCE_DORMANT], true)
                ? (int) ($data['audience_days'] ?? MarketingCampaign::DEFAULT_AUDIENCE_DAYS)
                : null,
            'cost_per_message' => round((float) ($data['cost_per_message'] ?? 0), 4),
            'attribution_days' => max(1, (int) ($data['attribution_days'] ?? 7)),
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ]);

        // New campaigns are drafts: the state is written here rather than left to
        // the column default, so the object that comes back from save() can answer
        // "has this gone out?" without a second read.
        if (! $campaign->exists) {
            $campaign->status = MarketingCampaign::STATUS_DRAFT;
        }

        $scheduled = $this->blankToNull($data['scheduled_at'] ?? null);

        if ($scheduled !== null && $campaign->isDraft()) {
            $campaign->status = MarketingCampaign::STATUS_SCHEDULED;
            $campaign->scheduled_at = $scheduled;
        }

        $campaign->created_by = $campaign->created_by ?: $actor?->id;
        $campaign->save();

        $this->audit->record([
            'action' => $before === null ? 'marketing.campaign_created' : 'marketing.campaign_updated',
            'entity_type' => 'marketing_campaign',
            'entity_id' => $campaign->id,
            'actor_id' => $actor?->id,
            'before' => $before,
            'after' => $campaign->only(['code', 'name', 'channel', 'audience', 'cost_per_message', 'scheduled_at', 'status']),
        ]);

        return $campaign;
    }

    /** Put a draft on the clock, or move one that is already there. */
    public function schedule(MarketingCampaign $campaign, ?User $actor, string $when): MarketingCampaign
    {
        $this->assertCampaign($campaign);

        if (! $campaign->isEditable()) {
            throw new RuntimeException('Only a draft or a scheduled campaign can be put on the clock.');
        }

        $at = \Carbon\Carbon::parse($when);

        if ($at->isPast()) {
            throw new RuntimeException('That time has already passed — launch it now, or pick a time still to come.');
        }

        $before = $campaign->only(['status', 'scheduled_at']);

        $campaign->fill([
            'status' => MarketingCampaign::STATUS_SCHEDULED,
            'scheduled_at' => $at,
        ])->save();

        $this->audit->record([
            'action' => 'marketing.campaign_scheduled',
            'entity_type' => 'marketing_campaign',
            'entity_id' => $campaign->id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => ['scheduled_at' => $at->toDateTimeString()],
        ]);

        return $campaign;
    }

    /** Take a scheduled campaign off the clock without killing it. */
    public function unschedule(MarketingCampaign $campaign, ?User $actor): MarketingCampaign
    {
        $this->assertCampaign($campaign);

        if (! $campaign->isScheduled()) {
            throw new RuntimeException('Only a scheduled campaign can be taken off the clock.');
        }

        $campaign->fill([
            'status' => MarketingCampaign::STATUS_DRAFT,
            'scheduled_at' => null,
        ])->save();

        $this->audit->record([
            'action' => 'marketing.campaign_unscheduled',
            'entity_type' => 'marketing_campaign',
            'entity_id' => $campaign->id,
            'actor_id' => $actor?->id,
            'after' => ['status' => MarketingCampaign::STATUS_DRAFT],
        ]);

        return $campaign;
    }

    /**
     * Send it. This is the only place a campaign turns into rows: the audience is
     * expanded now, every opt-out is honoured, every recipient gets a row, and
     * the ones actually written to are handed to the shared outbox.
     *
     * @param  array<int, int>  $customerIds  the hand-picked list, for the `manual` rule
     * @return array{campaign:MarketingCampaign, counts:array<string, int>}
     */
    public function launch(MarketingCampaign $campaign, ?User $actor, array $customerIds = []): array
    {
        $this->assertCampaign($campaign);

        if (! $campaign->isEditable()) {
            throw new RuntimeException(
                $campaign->isLaunched()
                    ? "{$campaign->code} has already gone out — launching it twice would write to everybody twice."
                    : 'A cancelled campaign is not launched.'
            );
        }

        $audience = $this->audience($campaign, $customerIds);

        if ($audience->isEmpty()) {
            throw new RuntimeException('That audience rule matches nobody today — nothing to send, and nothing invented to send it to.');
        }

        $company = Company::query()->find($this->companyId());
        $transport = $this->resolveTransport($campaign->channel);
        $counts = [
            'recipients' => 0,
            'queued' => 0,
            'held' => 0,
            'skipped_opted_out' => 0,
            'skipped_no_address' => 0,
            'skipped_blacklisted' => 0,
        ];

        DB::transaction(function () use ($campaign, $actor, $audience, $company, $transport, &$counts) {
            foreach ($audience as $customer) {
                $contact = $this->contactFor($campaign, $customer);

                $skip = $this->skipReason($campaign, $customer, $contact);

                $recipient = new CampaignRecipient([
                    'company_id' => $campaign->company_id,
                    'campaign_id' => $campaign->id,
                    'customer_id' => $customer->id,
                    'name' => (string) $customer->name,
                    'contact' => $contact,
                    'cost' => 0,
                ]);

                if ($skip !== null) {
                    $recipient->skip_reason = $skip;
                    $recipient->save();

                    $counts['recipients']++;
                    $counts['skipped_'.$skip] = ($counts['skipped_'.$skip] ?? 0) + 1;

                    continue;
                }

                $message = OutboxMessage::query()->create([
                    'company_id' => $campaign->company_id,
                    'channel' => $campaign->channel,
                    'message_template_id' => $campaign->template_id,
                    'marketing_campaign_id' => $campaign->id,
                    'recipient' => (string) $contact,
                    'subject' => $campaign->subject !== null
                        ? $this->render((string) $campaign->subject, $campaign, $customer, $company)
                        : null,
                    'body' => $this->render((string) $campaign->body, $campaign, $customer, $company),
                    'status' => $transport !== null ? OutboxMessage::STATUS_QUEUED : OutboxMessage::STATUS_NOT_CONFIGURED,
                    'provider_code' => $transport,
                    'queued_at' => now(),
                    'created_by' => $actor?->id,
                ]);

                $recipient->outbox_message_id = $message->id;
                $recipient->cost = round((float) $campaign->cost_per_message, 4);
                $recipient->save();

                $counts['recipients']++;
                $transport !== null ? $counts['queued']++ : $counts['held']++;
            }

            $campaign->fill([
                'status' => MarketingCampaign::STATUS_LAUNCHED,
                'launched_at' => now(),
            ])->save();

            $this->audit->record([
                'action' => 'marketing.campaign_launched',
                'entity_type' => 'marketing_campaign',
                'entity_id' => $campaign->id,
                'actor_id' => $actor?->id,
                'after' => [
                    'code' => $campaign->code,
                    'channel' => $campaign->channel,
                    'audience' => $campaign->audience,
                    'transport' => $transport,
                ] + $counts,
            ]);
        });

        return ['campaign' => $campaign->refresh(), 'counts' => $counts];
    }

    public function cancel(MarketingCampaign $campaign, ?User $actor, string $reason): MarketingCampaign
    {
        $this->assertCampaign($campaign);

        if ($campaign->isLaunched()) {
            throw new RuntimeException('A campaign that has already gone out cannot be cancelled — the messages are someone\'s inbox now.');
        }

        if ($campaign->isCancelled()) {
            throw new RuntimeException('This campaign is already cancelled.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why it is off — the report keeps the reason, and so does the audit trail.');
        }

        $before = $campaign->only(['status', 'scheduled_at']);

        $campaign->fill([
            'status' => MarketingCampaign::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'marketing.campaign_cancelled',
            'entity_type' => 'marketing_campaign',
            'entity_id' => $campaign->id,
            'actor_id' => $actor?->id,
            'before' => $before,
            'after' => ['reason' => $reason],
        ]);

        return $campaign;
    }

    /* ------------------------------------------------------------- the audience */

    /**
     * Who this campaign goes to, expanded from the rule *now*.
     *
     * @param  array<int, int>  $customerIds
     * @return Collection<int, Customer>
     */
    public function audience(MarketingCampaign $campaign, array $customerIds = []): Collection
    {
        $companyId = $this->companyId();
        $days = $campaign->windowDays();
        $since = \Carbon\Carbon::today()->subDays($days)->toDateString();

        // Blacklisted customers are deliberately *kept* in the expansion: they are
        // skipped one by one, with the reason written on the row, because a report
        // that cannot say “four people were not written to, and why” is a report
        // nobody can act on.
        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        if ($campaign->audience === MarketingCampaign::AUDIENCE_RECENT) {
            $customers->whereIn('id', $this->invoiceCustomerIds('>=', $since));
        } elseif ($campaign->audience === MarketingCampaign::AUDIENCE_DORMANT) {
            // Bought before the window and not since: the win-back list.
            $customers->whereIn('id', $this->invoiceCustomerIds('<', $since));
            $customers->whereNotIn('id', $this->invoiceCustomerIds('>=', $since));
        } elseif ($campaign->audience === MarketingCampaign::AUDIENCE_MANUAL) {
            $ids = array_values(array_unique(array_map('intval', $customerIds)));

            if ($ids === []) {
                return collect();
            }

            $customers->whereIn('id', $ids);
        }

        return $customers->orderBy('name')->get();
    }

    /** Customers with an invoice on either side of a date — the audience's raw material. */
    protected function invoiceCustomerIds(string $operator, string $date): array
    {
        return DB::table('invoices')
            ->where('company_id', $this->companyId())
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereDate('invoice_date', $operator, $date)
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The list a hand-picked campaign can choose from: the same customers the
     * `all` rule would reach, with their recent buying kept beside them so the
     * choice is informed rather than alphabetical.
     *
     * @return Collection<int, Customer>
     */
    public function choosable(): Collection
    {
        $since = \Carbon\Carbon::today()->subDays(MarketingCampaign::DEFAULT_AUDIENCE_DAYS)->toDateString();

        return Customer::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->where('is_blacklisted', false)
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->each(function (Customer $customer) use ($since) {
                $customer->setAttribute('recent_invoices', (int) DB::table('invoices')
                    ->where('company_id', $this->companyId())
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['issued', 'partial', 'paid'])
                    ->whereDate('invoice_date', '>=', $since)
                    ->count());
            });
    }

    /**
     * Who this channel can actually reach, and who it cannot — with the reason on
     * each row.
     *
     * This is the honest version of a “contacts” list. A reachable contact is a
     * customer with an address for that channel who is neither blacklisted nor
     * opted out; everybody else is listed too, with the single reason they would
     * be skipped if a campaign went out today. Nothing is filtered away silently:
     * the counts at the top of the page and the rows underneath come from the same
     * pass over the same customers, and they are the same rules the dispatcher
     * applies when it launches.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, reachable: int, opted_out: int, blacklisted: int, no_address: int}
     */
    public function contacts(string $channel): array
    {
        $rows = Customer::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(function (Customer $customer) use ($channel) {
                $contact = $this->contactFor(new MarketingCampaign(['channel' => $channel]), $customer);

                $verdict = 'reachable';
                $reason = null;

                if (! $customer->is_blacklisted && $contact === null) {
                    $verdict = CampaignRecipient::SKIP_NO_ADDRESS;
                    $reason = 'No '.($channel === MarketingCampaign::CHANNEL_EMAIL ? 'email address' : 'phone number').' on file.';
                } elseif ($customer->is_blacklisted) {
                    $verdict = CampaignRecipient::SKIP_BLACKLISTED;
                    $reason = 'Blacklisted — nothing is marketed to them.';
                }

                if ($verdict === 'reachable' && $this->isOptedOut($channel, $contact)) {
                    $verdict = CampaignRecipient::SKIP_OPTOUT;
                    $reason = 'They asked not to be written to by '.(MarketingCampaign::CHANNELS[$channel]['label'] ?? $channel).'.';
                }

                return [
                    'customer' => $customer,
                    'contact' => $contact,
                    'verdict' => $verdict,
                    'reason' => $reason,
                ];
            });

        return [
            'rows' => $rows,
            'reachable' => $rows->where('verdict', 'reachable')->count(),
            'opted_out' => $rows->where('verdict', CampaignRecipient::SKIP_OPTOUT)->count(),
            'blacklisted' => $rows->where('verdict', CampaignRecipient::SKIP_BLACKLISTED)->count(),
            'no_address' => $rows->where('verdict', CampaignRecipient::SKIP_NO_ADDRESS)->count(),
        ];
    }

    /**
     * What is actually in the outbox for these campaigns.
     *
     * The desk reads delivery from the outbox and nowhere else — this is that
     * reading, filtered by channel and state. A held message is shown as held with
     * the reason the outbox recorded, and a failure keeps the provider's own words
     * rather than a paraphrase.
     *
     * @return array{messages: \Illuminate\Contracts\Pagination\LengthAwarePaginator, counts: array<string, int>}
     */
    public function deliveries(?string $channel = null, ?string $state = null, string $search = ''): array
    {
        $base = OutboxMessage::query()
            ->where('company_id', $this->companyId())
            ->whereNotNull('marketing_campaign_id');

        $counts = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $messages = (clone $base)
            ->when($channel !== null, fn (Builder $query) => $query->where('channel', $channel))
            ->when($state !== null, fn (Builder $query) => $query->where('status', $state))
            ->when(trim($search) !== '', function (Builder $query) use ($search) {
                $needle = '%'.trim($search).'%';
                $query->where(fn (Builder $inner) => $inner->where('recipient', 'like', $needle)->orWhere('body', 'like', $needle));
            })
            ->with(['campaign', 'template'])
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        // The outbox carries the contact; the person behind it is on the recipient
        // row. A delivery report that lists phone numbers and no names is a report
        // somebody has to cross-reference by hand.
        $names = CampaignRecipient::query()
            ->whereIn('outbox_message_id', $messages->pluck('id')->all())
            ->pluck('name', 'outbox_message_id')
            ->all();

        return ['messages' => $messages, 'counts' => $counts, 'names' => $names];
    }

    /**
     * The campaigns the dispatcher should have sent by now, and the sending.
     *
     * Scheduled campaigns go out with the audience as it is on the run — which is
     * the whole point of storing the rule rather than the list. A campaign whose
     * audience matches nobody at that moment is *not* launched: it stays scheduled
     * and says so, because marking it “sent” with no recipients would be a lie the
     * report would then carry forever.
     *
     * @return array<int, array{campaign: MarketingCampaign, counts: array<string, int>|null, error: string|null}>
     */
    public function dispatchDue(\Carbon\Carbon $now): array
    {
        $results = [];

        $due = MarketingCampaign::query()
            ->where('company_id', $this->companyId())
            ->due($now)
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $campaign) {
            try {
                $results[] = [
                    'campaign' => $campaign,
                    'counts' => $this->launch($campaign, null)['counts'],
                    'error' => null,
                ];
            } catch (\RuntimeException $error) {
                $results[] = ['campaign' => $campaign, 'counts' => null, 'error' => $error->getMessage()];
            }
        }

        return $results;
    }

    /* ---------------------------------------------------------------- the desk */

    /**
     * The figures the desk opens on. Every one is counted from rows: the
     * campaigns themselves, the recipients, and the outbox messages they point
     * at — there is no counter column anywhere in this module.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $campaigns = MarketingCampaign::query()->where('company_id', $this->companyId())->get();

        $recipients = CampaignRecipient::query()
            ->where('company_id', $this->companyId())
            ->with('message')
            ->get();

        $handed = $recipients->filter(fn (CampaignRecipient $row) => $row->wasHandedOver());

        return [
            'campaigns' => $campaigns->count(),
            'drafts' => $campaigns->where('status', MarketingCampaign::STATUS_DRAFT)->count(),
            'scheduled' => $campaigns->where('status', MarketingCampaign::STATUS_SCHEDULED)->count(),
            'launched' => $campaigns->where('status', MarketingCampaign::STATUS_LAUNCHED)->count(),
            'recipients' => $recipients->count(),
            'queued' => $handed->filter(fn (CampaignRecipient $row) => $row->state() === 'queued')->count(),
            'held' => $handed->filter(fn (CampaignRecipient $row) => $row->state() === 'not_configured')->count(),
            'sent' => $handed->filter(fn (CampaignRecipient $row) => $row->state() === 'sent')->count(),
            'failed' => $handed->filter(fn (CampaignRecipient $row) => $row->state() === 'failed')->count(),
            'skipped_opted_out' => $recipients->where('skip_reason', CampaignRecipient::SKIP_OPTOUT)->count(),
            'skipped_no_address' => $recipients->where('skip_reason', CampaignRecipient::SKIP_NO_ADDRESS)->count(),
            'skipped_blacklisted' => $recipients->where('skip_reason', CampaignRecipient::SKIP_BLACKLISTED)->count(),
            'cost' => round((float) $recipients->sum(fn (CampaignRecipient $row) => (float) $row->cost), 4),
            'optouts' => MarketingOptout::query()->where('company_id', $this->companyId())->count(),
        ];
    }

    /**
     * Cost against revenue, per campaign. Revenue is what the recipients
     * actually bought inside their attribution window — counted from invoices,
     * never from a tracking pixel nobody in this application owns.
     *
     * @return array{campaigns:Collection<int, array<string, mixed>>, totals:array<string, float|int>}
     */
    public function performance(?string $channel = null): array
    {
        $companyId = $this->companyId();

        $campaigns = MarketingCampaign::query()
            ->where('company_id', $companyId)
            ->when($channel !== null, fn (Builder $query) => $query->where('channel', $channel))
            ->with(['recipients.message'])
            ->orderByDesc('id')
            ->get();

        $rows = $campaigns->map(function (MarketingCampaign $campaign) use ($companyId) {
            $recipients = $campaign->recipients;
            $handed = $recipients->filter(fn (CampaignRecipient $row) => $row->wasHandedOver());

            $states = $handed->groupBy(fn (CampaignRecipient $row) => $row->state())
                ->map(fn (Collection $group) => $group->count());

            // Only the recipients a transport was actually handed count: a message
            // that was never sent cannot have caused an order, and counting a skipped
            // customer's invoice would credit the campaign with a sale it never asked for.
            $customerIds = $handed->pluck('customer_id')->unique()->values()->all();
            $revenue = 0.0;
            $orders = 0;

            if ($campaign->isLaunched() && $campaign->launched_at !== null && $customerIds !== []) {
                $query = DB::table('invoices')
                    ->where('company_id', $companyId)
                    ->whereIn('customer_id', $customerIds)
                    ->whereIn('status', ['issued', 'partial', 'paid'])
                    ->whereDate('invoice_date', '>=', $campaign->launched_at->toDateString())
                    ->whereDate('invoice_date', '<=', $campaign->attributionEndsAt()?->toDateString() ?? $campaign->launched_at->toDateString());

                $revenue = round((float) $query->sum('grand_total'), 2);
                $orders = (int) (clone $query)->count('id');
            }

            $cost = round((float) $recipients->sum(fn (CampaignRecipient $row) => (float) $row->cost), 4);

            return [
                'campaign' => $campaign,
                'recipients' => $recipients->count(),
                'handed' => $handed->count(),
                'queued' => (int) ($states['queued'] ?? 0),
                'held' => (int) ($states['not_configured'] ?? 0),
                'sent' => (int) ($states['sent'] ?? 0),
                'failed' => (int) ($states['failed'] ?? 0),
                'skipped_opted_out' => $recipients->where('skip_reason', CampaignRecipient::SKIP_OPTOUT)->count(),
                'cost' => $cost,
                'orders' => $orders,
                'revenue' => $revenue,
                'roas' => $cost > 0 ? round($revenue / $cost, 2) : null,
            ];
        });

        return [
            'campaigns' => $rows,
            'totals' => [
                'campaigns' => $rows->count(),
                'recipients' => (int) $rows->sum('recipients'),
                'cost' => round((float) $rows->sum('cost'), 4),
                'orders' => (int) $rows->sum('orders'),
                'revenue' => round((float) $rows->sum('revenue'), 2),
            ],
        ];
    }

    /* --------------------------------------------------------------- opt-outs */

    /**
     * Write down that somebody said no.
     *
     * Keyed on the contact, so the same number writing STOP twice is one entry;
     * `all` blocks every channel, which is what “stop everything” means.
     */
    public function optOut(
        string $channel,
        string $contact,
        ?User $actor,
        ?string $reason = null,
        string $source = 'manual',
        ?Customer $customer = null,
        ?MarketingCampaign $campaign = null,
    ): MarketingOptout {
        $channel = $this->optoutChannel($channel);
        $contact = trim($contact);

        if ($contact === '') {
            throw new RuntimeException('An opt-out needs the phone number or address that asked for it.');
        }

        if (! array_key_exists($source, MarketingOptout::SOURCES)) {
            throw new RuntimeException('Unknown opt-out source.');
        }

        $existing = MarketingOptout::query()
            ->where('company_id', $this->companyId())
            ->where('channel', $channel)
            ->where('contact', $contact)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $optout = MarketingOptout::query()->create([
            'company_id' => $this->companyId(),
            'customer_id' => $customer?->id,
            'campaign_id' => $campaign?->id,
            'channel' => $channel,
            'contact' => $contact,
            'reason' => $this->blankToNull($reason),
            'source' => $source,
            'created_by' => $actor?->id,
        ]);

        $this->audit->record([
            'action' => 'marketing.optout_recorded',
            'entity_type' => 'marketing_optout',
            'entity_id' => $optout->id,
            'actor_id' => $actor?->id,
            'after' => ['channel' => $channel, 'contact' => $contact, 'source' => $source],
        ]);

        return $optout;
    }

    /** Somebody changed their mind — deliberately, and with a name on it. */
    public function liftOptOut(MarketingOptout $optout, ?User $actor): void
    {
        if ((int) $optout->company_id !== $this->companyId()) {
            throw new RuntimeException('That opt-out belongs to another company.');
        }

        $this->audit->record([
            'action' => 'marketing.optout_lifted',
            'entity_type' => 'marketing_optout',
            'entity_id' => $optout->id,
            'actor_id' => $actor?->id,
            'before' => ['channel' => $optout->channel, 'contact' => $optout->contact],
        ]);

        $optout->delete();
    }

    /** @return Collection<int, MarketingOptout> */
    public function optOuts(?string $channel = null, ?string $search = null): Collection
    {
        return MarketingOptout::query()
            ->where('company_id', $this->companyId())
            ->when($channel !== null, fn (Builder $query) => $query->whereIn('channel', [$channel, MarketingOptout::CHANNEL_ALL]))
            ->when($search !== null && trim($search) !== '', fn (Builder $query) => $query->where('contact', 'like', '%'.trim($search).'%'))
            ->with(['customer', 'campaign', 'creator'])
            ->orderByDesc('id')
            ->get();
    }

    /** Is this contact blocked on this channel — by itself, or by an `all` entry? */
    public function isOptedOut(string $channel, ?string $contact): bool
    {
        if ($contact === null || trim($contact) === '') {
            return false;
        }

        return MarketingOptout::query()
            ->where('company_id', $this->companyId())
            ->where('contact', trim($contact))
            ->whereIn('channel', [$channel, MarketingOptout::CHANNEL_ALL])
            ->exists();
    }

    /* --------------------------------------------------------------- internals */

    /**
     * Transport readiness — the same truthful rule the bulk-message action uses:
     * SMS needs an active provider that is actually configured, email needs a
     * real mail driver (`log`/`array` never count as writing to a customer), and
     * WhatsApp and Push have no transport registry at all, so they are held.
     */
    public function resolveTransport(string $channel): ?string
    {
        if ($channel === MarketingCampaign::CHANNEL_SMS) {
            return SmsProvider::query()
                ->where('company_id', $this->companyId())
                ->where('is_active', true)
                ->where('config_status', 'configured')
                ->orderBy('id')
                ->value('code');
        }

        if ($channel === MarketingCampaign::CHANNEL_EMAIL) {
            $driver = (string) config('mail.default', 'log');

            return in_array($driver, ['log', 'array', ''], true) ? null : $driver;
        }

        return null;
    }

    /** The place a message is written to on this channel, or null if there is none. */
    protected function contactFor(MarketingCampaign $campaign, Customer $customer): ?string
    {
        $value = $campaign->contactField() === 'email'
            ? $customer->email
            : $customer->phone;

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** Why this recipient is skipped, or null when they are written to. */
    protected function skipReason(MarketingCampaign $campaign, Customer $customer, ?string $contact): ?string
    {
        if ($contact === null) {
            return CampaignRecipient::SKIP_NO_ADDRESS;
        }

        if ($customer->is_blacklisted) {
            return CampaignRecipient::SKIP_BLACKLISTED;
        }

        if ($this->isOptedOut($campaign->channel, $contact)) {
            return CampaignRecipient::SKIP_OPTOUT;
        }

        return null;
    }

    /** {customer}, {company}, {month}, {date}, {channel} — and nothing else. */
    public function render(string $text, MarketingCampaign $campaign, Customer $customer, ?Company $company = null): string
    {
        return strtr($text, [
            '{customer}' => (string) $customer->name,
            '{company}' => (string) ($company?->name ?? ''),
            '{month}' => now()->format('F'),
            '{date}' => now()->format('d M Y'),
            '{channel}' => $campaign->channelLabel(),
        ]);
    }

    /** MC-000001, allocated per company and never reused. */
    public function nextCode(): string
    {
        $companyId = $this->companyId();

        $last = MarketingCampaign::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('code');

        $number = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
            $number = (int) $matches[1] + 1;
        }

        do {
            $code = self::CODE_PREFIX.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (MarketingCampaign::query()->where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    protected function optoutChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! array_key_exists($channel, MarketingOptout::CHANNELS)) {
            throw new RuntimeException('An opt-out is either for every channel or for one of the four.');
        }

        return $channel;
    }

    protected function assertCampaign(MarketingCampaign $campaign): void
    {
        if ((int) $campaign->company_id !== $this->companyId()) {
            throw new RuntimeException('That campaign belongs to another company.');
        }
    }

    protected function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
