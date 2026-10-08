@php
    /* §11 — the campaign desk: what is running, on which channel. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
@endphp

<x-ui.page-header
    eyebrow="Marketing · Campaigns{{ $channel ? ' · '.$channels[$channel]['label'] : '' }}"
    title="Who we wrote to, what it cost, and what came back"
    subtitle="A campaign is a draft until somebody launches it, and launching expands the audience rule on the day — so “everybody who bought in the last 90 days” means that when it goes out. Opt-outs are honoured by the dispatcher before anything is queued, skipped recipients are rows with the reason on them, and no message is ever marked sent by this application: messages are handed to the outbox, which only says queued when a real transport exists."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('marketing.campaigns.create', $channel ? ['channel' => $channel] : []) }}">
                <i class="bi bi-megaphone" aria-hidden="true"></i> New campaign
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('marketing.templates') }}">
            <i class="bi bi-file-text" aria-hidden="true"></i> Templates
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.optouts') }}">
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Opt-outs
            @if ($overview['optouts'] > 0)
                <span class="erp-chip erp-chip-outline ms-1">{{ $overview['optouts'] }}</span>
            @endif
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.reports') }}">
            <i class="bi bi-graph-up" aria-hidden="true"></i> Cost vs revenue
        </a>
    </x-slot:actions>
</x-ui.page-header>

@if ($preset !== null)
    <div class="erp-note erp-note-info mb-3">
        <i class="bi {{ $preset['icon'] }}" aria-hidden="true"></i>
        <div>
            <strong>{{ $preset['title'] }}.</strong>
            {{ $preset['note'] }}
            @if ($canManage)
                <a class="ms-1" href="{{ route('marketing.campaigns.create', ['audience' => $audience]) }}">Write one for that audience</a>.
            @endif
        </div>
    </div>
@endif

<div class="erp-kpi-grid">
    <x-ui.kpi label="Campaigns" :value="$overview['campaigns']" icon="bi-megaphone" hero
              :hint="$overview['drafts'].' draft(s), '.$overview['scheduled'].' on the clock'" />
    <x-ui.kpi label="Recipients" :value="$overview['recipients']" icon="bi-people"
              :hint="$overview['skipped_opted_out'].' were skipped because they opted out'" />
    <x-ui.kpi label="Queued" :value="$overview['queued']" icon="bi-send"
              hint="Handed to a transport that actually exists" />
    <x-ui.kpi label="Held" :value="$overview['held']" icon="bi-pause-circle"
              :hint="$overview['held'] > 0 ? 'No transport configured for that channel — not a delivery, and not pretending to be one' : 'Everything handed over found a transport'" />
    <x-ui.kpi label="Cost so far" :value="$money($overview['cost'])" icon="bi-cash-stack"
              hint="Message price × the messages actually handed over" />
    <x-ui.kpi label="Opt-outs" :value="$overview['optouts']" icon="bi-slash-circle"
              hint="Contacts who asked us not to write to them" :href="route('marketing.optouts')" />
</div>

@if ($due->isNotEmpty())
    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <div>
            <strong>{{ $due->count() }} campaign(s) are due and waiting for the dispatcher.</strong>
            Scheduled campaigns go out on the next dispatch run ({{ $due->take(3)->map(fn ($campaign) => $campaign->code.' at '.$campaign->scheduled_at?->format('d M H:i'))->implode(', ') }}) — or launch one by hand from its page.
        </div>
    </div>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('marketing.campaigns.index') }}">
    @if ($preset !== null)
        <input type="hidden" name="preset" value="{{ request('preset') }}">
    @endif

    <div class="erp-filter">
        <label class="form-label" for="channel">Channel</label>
        <select class="form-select" name="channel" id="channel" data-erp-autosubmit>
            <option value="">Every channel</option>
            @foreach ($channels as $key => $row)
                <option value="{{ $key }}" @selected($channel === $key)>{{ $row['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">State</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">Every state</option>
            @foreach (\App\Domain\Marketing\MarketingCampaign::STATUSES as $option)
                <option value="{{ $option }}" @selected(request('status') === $option)>{{ ucfirst($option) }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="audience">Audience</label>
        <select class="form-select" name="audience" id="audience" data-erp-autosubmit>
            <option value="">Every audience rule</option>
            @foreach ($audiences as $key => $rule)
                <option value="{{ $key }}" @selected($audience === $key)>{{ $rule['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('marketing.campaigns.index', $preset !== null ? ['preset' => request('preset')] : []) }}">Reset</a>
    </div>
</form>

<x-ui.table-shell
    title="{{ $channel ? $channels[$channel]['label'].' campaigns' : 'Every campaign' }}"
    :count="$campaigns->total().' campaign(s)'">
    <thead>
        <tr>
            <th>Campaign</th>
            <th>Channel</th>
            <th>Audience</th>
            <th class="text-end">Recipients</th>
            <th>Went out</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($campaigns as $campaign)
            <tr>
                <td data-label="Campaign">
                    <a class="erp-cell-strong" href="{{ route('marketing.campaigns.show', $campaign) }}">{{ $campaign->name }}</a>
                    <div class="erp-td-muted">{{ $campaign->code }}{{ $campaign->objective ? ' · '.$campaign->objective : '' }}</div>
                </td>
                <td data-label="Channel">
                    <i class="bi {{ $campaign->channelIcon() }} me-1" aria-hidden="true"></i>{{ $campaign->channelLabel() }}
                </td>
                <td data-label="Audience">
                    {{ $campaign->audienceLabel() }}
                    @if ($campaign->audience_days !== null)
                        <div class="erp-td-muted">window {{ $campaign->audience_days }} day(s)</div>
                    @endif
                </td>
                <td data-label="Recipients" class="erp-td-num text-end">{{ $campaign->recipients_count }}</td>
                <td data-label="Went out">
                    {{ $campaign->launched_at?->format('d M Y H:i') ?? ($campaign->scheduled_at?->format('d M Y H:i') ?? '—') }}
                    <div class="erp-td-muted">
                        {{ $campaign->isLaunched() ? 'launched' : ($campaign->isScheduled() ? 'scheduled' : 'not yet') }}
                    </div>
                </td>
                <td data-label="State"><x-ui.status :value="$campaign->status" :label="$campaign->stateLabel()" /></td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.campaigns.show', $campaign) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="No campaigns on this channel yet"
                        text="Write down who you want to reach, what you want them to read, and how the audience is worked out. Nothing goes out until somebody launches it — and when it does, every recipient is a row you can hold the result against."
                        icon="bi-megaphone"
                        :action="$canManage ? 'New campaign' : null"
                        :href="$canManage ? route('marketing.campaigns.create') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($campaigns->hasPages())
        <x-slot:footer>{{ $campaigns->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
