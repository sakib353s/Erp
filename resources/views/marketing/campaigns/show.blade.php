@php
    /* §11 — one campaign: what it says, who it went to, and what came back. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $editable = $campaign->isEditable();
    $states = [
        'queued' => 'Queued',
        'not_configured' => 'Held',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'handed' => 'Handed over',
        \App\Domain\Marketing\CampaignRecipient::SKIP_OPTOUT => 'Opted out',
        \App\Domain\Marketing\CampaignRecipient::SKIP_NO_ADDRESS => 'No address',
        \App\Domain\Marketing\CampaignRecipient::SKIP_BLACKLISTED => 'Blacklisted',
    ];
@endphp

<x-ui.page-header
    :eyebrow="'Marketing · '.$campaign->channelLabel().' · '.$campaign->code"
    :title="$campaign->name"
    :subtitle="$campaign->objective ?: $campaign->audienceBlurb()"
    :pin="true">
    <x-slot:actions>
        @if ($canManage && $editable)
            <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.edit', $campaign) }}">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.index', ['channel' => $campaign->channel]) }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> All {{ strtolower($campaign->channelLabel()) }} campaigns
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.reports') }}">
            <i class="bi bi-graph-up" aria-hidden="true"></i> Cost vs revenue
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="State" :value="$campaign->stateLabel()" :icon="$campaign->channelIcon()" hero
              :hint="$campaign->isLaunched()
                    ? 'Went out '.$campaign->launched_at?->format('d M Y H:i')
                    : ($campaign->isScheduled()
                        ? 'On the clock for '.$campaign->scheduled_at?->format('d M Y H:i')
                        : 'A draft — nothing has gone anywhere')" />
    <x-ui.kpi label="Audience" :value="$campaign->audienceLabel()" icon="bi-people"
              :hint="$campaign->audience_days !== null
                    ? 'window '.$campaign->audience_days.' day(s), expanded at launch'
                    : $campaign->audienceBlurb()" />
    <x-ui.kpi label="Recipients" :value="$campaign->recipients->count()" icon="bi-send"
              :hint="$campaign->isLaunched() ? 'Rows written when it went out' : 'Nobody yet — this count appears at launch'" />
    <x-ui.kpi label="Cost per message" :value="$money($campaign->cost_per_message)" icon="bi-cash-stack"
              hint="Frozen on each recipient at launch" />
    <x-ui.kpi label="Counts orders for" :value="$campaign->attribution_days.' day(s)'" icon="bi-calendar-check"
              :hint="$campaign->attributionEndsAt() ? 'Until '.$campaign->attributionEndsAt()?->format('d M Y') : 'The window opens when it launches'" />
    @if ($performance !== null)
        <x-ui.kpi label="Revenue in window" :value="$money($performance['revenue'])" icon="bi-graph-up-arrow"
                  :hint="$performance['orders'].' invoice(s) from recipients · '.($performance['roas'] !== null ? $performance['roas'].'× the cost' : 'no cost recorded')" />
    @endif
</div>

@if ($campaign->isCancelled())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-slash-circle" aria-hidden="true"></i>
        <div>
            <strong>Cancelled {{ $campaign->cancelled_at?->format('d M Y') }}.</strong>
            {{ $campaign->cancel_reason }}
        </div>
    </div>
@endif

@if ($editable && ! $campaign->isScheduled())
    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>This is still a draft.</strong>
            Launching it expands the audience rule today
            @if ($previewCount !== null)
                — {{ $previewCount }} customer(s) match it right now —
            @endif
            skips anybody who has opted out, blacklisted customers and contacts with no address, and hands the rest to the outbox. Messages are never marked sent here: the outbox says <em>queued</em> when a real transport exists, and <em>held</em> when none does.
        </div>
    </div>
@endif

<div class="erp-card mb-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-card-title">The message</h2>
            <p class="erp-card-sub">Rendered per recipient at launch — the placeholders below were replaced on every copy.</p>
        </div>
        @if ($campaign->template)
            <span class="erp-chip erp-chip-outline">from {{ $campaign->template->name }}</span>
        @endif
    </div>

    @if ($campaign->subject !== null)
        <div class="mb-2"><span class="erp-field-label">Subject</span> <strong>{{ $campaign->subject }}</strong></div>
    @endif

    <pre class="erp-pre">{{ $campaign->body }}</pre>

    <dl class="erp-dl erp-dl-tight mt-3">
        <dt>Channel</dt>
        <dd><i class="bi {{ $campaign->channelIcon() }} me-1" aria-hidden="true"></i>{{ $campaign->channelLabel() }} — written to the customer's {{ $campaign->contactField() }}</dd>

        <dt>Written by</dt>
        <dd>{{ $campaign->creator?->name ?? 'the system' }}{{ $campaign->created_at ? ' on '.$campaign->created_at->format('d M Y') : '' }}</dd>

        @if ($campaign->notes)
            <dt>Notes</dt>
            <dd>{{ $campaign->notes }}</dd>
        @endif
    </dl>
</div>

@if ($performance !== null && $campaign->isLaunched())
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What came of it</h2>
                <p class="erp-card-sub">
                    Revenue is the invoices raised by these recipients between
                    {{ $campaign->launched_at?->format('d M Y') }} and {{ $campaign->attributionEndsAt()?->format('d M Y') }} —
                    counted from invoices, never from a click nobody recorded.
                </p>
            </div>
        </div>

        <div class="erp-kpi-grid">
            <x-ui.kpi label="Handed over" :value="$performance['handed']" icon="bi-send"
                      :hint="$performance['queued'].' queued · '.$performance['held'].' held'" />
            <x-ui.kpi label="Sent" :value="$performance['sent']" icon="bi-check2-circle"
                      hint="Only a real transport can report this" />
            <x-ui.kpi label="Failed" :value="$performance['failed']" icon="bi-exclamation-triangle"
                      :hint="$performance['failed'] > 0 ? 'The outbox kept the error on each one' : 'Nothing failed'" />
            <x-ui.kpi label="Skipped" :value="$performance['recipients'] - $performance['handed']" icon="bi-slash-circle"
                      :hint="$performance['skipped_opted_out'].' opted out'" />
            <x-ui.kpi label="Cost" :value="$money($performance['cost'])" icon="bi-cash-stack" />
            <x-ui.kpi label="Revenue" :value="$money($performance['revenue'])" icon="bi-graph-up-arrow"
                      :hint="$performance['roas'] !== null ? $performance['roas'].'× the message cost' : 'Cost is zero, so there is no ratio to show'" />
        </div>
    </div>
@endif

@if ($canManage && $editable)
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The decision</h2>
                <p class="erp-card-sub">Three different things, and the difference matters: launching sends it now, scheduling sends it later by itself, cancelling means it never goes.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="erp-subcard">
                    <h3 class="erp-subcard-title">Launch now</h3>
                    <p class="form-text">
                        The audience is worked out at this moment. This cannot be undone — the messages become rows in the outbox and the campaign becomes history.
                    </p>

                    @error('launch')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                    <form method="POST" action="{{ route('marketing.campaigns.launch', $campaign) }}">
                        @csrf
                        @if ($campaign->audience === \App\Domain\Marketing\MarketingCampaign::AUDIENCE_MANUAL)
                            <p class="mb-2">
                                <strong>This one sends to a hand-picked list.</strong> Tick the customers it goes to, below.
                                @if ($customers->isEmpty())
                                    There are no active customers to pick from, so there is nobody to send to yet.
                                @endif
                            </p>
                            <div class="row g-2 mb-2" style="max-height: 260px; overflow-y: auto;">
                                @foreach ($customers as $customer)
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="customer_ids[]" value="{{ $customer->id }}"
                                                   id="launch_customer_{{ $customer->id }}">
                                            <label class="form-check-label" for="launch_customer_{{ $customer->id }}">
                                                {{ $customer->name }}
                                                <small class="erp-td-muted d-block">{{ $customer->phone ?? $customer->email ?? 'no contact' }}</small>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <button class="btn btn-primary" type="submit"
                                data-confirm="Launch {{ $campaign->code }} now? The audience is expanded today and the messages are handed to the outbox — this cannot be undone.">
                            <i class="bi bi-send" aria-hidden="true"></i> Launch {{ $campaign->code }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="erp-subcard">
                    <h3 class="erp-subcard-title">Put it on the clock</h3>
                    <p class="form-text">
                        The dispatcher picks scheduled campaigns up when their time comes and launches them with the audience as it is that day. Take it off the clock at any point before then.
                    </p>

                    @error('scheduled_at')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                    @if ($campaign->isScheduled())
                        <p class="mb-2">
                            Scheduled for <strong>{{ $campaign->scheduled_at?->format('d M Y \a\t H:i') }}</strong>.
                        </p>
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('marketing.campaigns.schedule', $campaign) }}" class="d-flex gap-2 align-items-start">
                                @csrf
                                <input class="form-control erp-filter-wide" type="datetime-local" name="scheduled_at"
                                       value="{{ $campaign->scheduled_at?->format('Y-m-d\TH:i') }}" required>
                                <button class="btn btn-outline-secondary" type="submit">Move it</button>
                            </form>
                            <form method="POST" action="{{ route('marketing.campaigns.unschedule', $campaign) }}">
                                @csrf
                                <button class="btn btn-link" type="submit" data-confirm="Take {{ $campaign->code }} off the clock? It stays a draft.">Take it off the clock</button>
                            </form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('marketing.campaigns.schedule', $campaign) }}" class="d-flex gap-2 align-items-start">
                            @csrf
                            <input class="form-control erp-filter-wide" type="datetime-local" name="scheduled_at"
                                   value="{{ old('scheduled_at', now()->addDay()->format('Y-m-d\T09:00')) }}" required>
                            <button class="btn btn-outline-secondary" type="submit">Schedule it</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="col-12">
                <details class="erp-inline-form">
                    <summary class="btn btn-sm btn-outline-danger">Call it off</summary>
                    <form method="POST" action="{{ route('marketing.campaigns.cancel', $campaign) }}" class="mt-2 d-flex gap-2 align-items-start">
                        @csrf
                        <input class="form-control @error('cancel_reason') is-invalid @enderror" type="text" name="cancel_reason"
                               maxlength="500" placeholder="Why it is off — the report keeps the reason" required>
                        @error('cancel_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button class="btn btn-outline-danger" type="submit" data-confirm="Cancel {{ $campaign->code }}? It will not go out at all.">Cancel it</button>
                    </form>
                    <p class="form-text mt-2 mb-0">Cancelling is recorded with your name and shows on the campaign for good. It is not the same as deleting — a decision that was made stays legible.</p>
                </details>
            </div>
        </div>
    </div>
@endif

<x-ui.table-shell
    title="Recipients"
    :count="$recipients->total().' row(s)'"
    stack="true">
    <x-slot:tools>
        <form class="erp-filterbar" method="GET" action="{{ route('marketing.campaigns.show', $campaign) }}">
            <div class="erp-filter">
                <label class="form-label" for="state">State</label>
                <select class="form-select" name="state" id="state" data-erp-autosubmit>
                    <option value="">Every state</option>
                    @foreach ($states as $key => $label)
                        <option value="{{ $key }}" @selected(request('state') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-filterbar-actions">
                <button class="btn btn-outline-secondary" type="submit">Filter</button>
                @if (request('state'))
                    <a class="btn btn-link" href="{{ route('marketing.campaigns.show', $campaign) }}">Reset</a>
                @endif
            </div>
        </form>
    </x-slot:tools>

    <thead>
        <tr>
            <th>Customer</th>
            <th>Written to</th>
            <th>State</th>
            <th class="text-end">Cost</th>
            <th>Message</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($recipients as $recipient)
            <tr>
                <td data-label="Customer">
                    @if ($recipient->customer)
                        <a class="erp-cell-strong" href="{{ route('customers.show', $recipient->customer_id) }}">{{ $recipient->name }}</a>
                    @else
                        <span class="erp-cell-strong">{{ $recipient->name }}</span>
                    @endif
                    @if ($recipient->customer?->is_blacklisted)
                        <div class="erp-td-muted">blacklisted</div>
                    @endif
                </td>
                <td data-label="Written to">{{ $recipient->contact ?? '—' }}</td>
                <td data-label="State">
                    @if ($recipient->isSkipped())
                        <x-ui.status value="warn" :label="$recipient->stateLabel()" />
                        <div class="erp-td-muted">{{ $recipient->skipLabel() }}</div>
                    @else
                        <x-ui.status :value="$recipient->state()" :label="$recipient->stateLabel()" />
                        @if ($recipient->message?->provider_code)
                            <div class="erp-td-muted">via {{ $recipient->message->provider_code }}</div>
                        @elseif ($recipient->state() === 'not_configured')
                            <div class="erp-td-muted">no transport configured — held, not sent</div>
                        @endif
                    @endif
                </td>
                <td data-label="Cost" class="erp-td-num text-end">{{ $money($recipient->cost) }}</td>
                <td data-label="Message">
                    @if ($recipient->message)
                        <span class="erp-td-muted">#{{ $recipient->message_id }}</span>
                    @else
                        <span class="erp-td-muted">not handed over</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty
                        title="{{ $campaign->isLaunched() ? 'No recipients match that filter' : 'Nobody has been written to yet' }}"
                        text="{{ $campaign->isLaunched()
                            ? 'Every recipient of this campaign is a row — try another state.'
                            : 'The audience is expanded when this campaign is launched, and every person it reaches becomes a row here — including the ones it skipped, with the reason.' }}"
                        icon="bi-people" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($recipients->hasPages())
        <x-slot:footer>{{ $recipients->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
