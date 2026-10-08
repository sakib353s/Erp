@php
    /* §11 — what the outbox actually holds for the campaigns. */
    $messages = $delivered['messages'];
    $counts = $delivered['counts'];
    $names = $delivered['names'] ?? [];
    $stateLabels = [
        'queued' => 'Queued',
        'not_configured' => 'Held',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];
    $total = array_sum($counts);
@endphp

<x-ui.page-header
    eyebrow="Marketing · Delivery{{ $channel ? ' · '.$channels[$channel]['label'] : '' }}"
    title="What each message actually did"
    subtitle="Read from the outbox and nowhere else. Held means no transport is configured for that channel — the message is written, costed and waiting, and it has not been sent to anybody. Queued means a real transport took it. Sent means that transport confirmed it. Failed keeps the provider's own error rather than a paraphrase of it. Nothing in this application marks a message sent, which is why this page can be trusted on the days when it says held."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.index', $channel ? ['channel' => $channel] : []) }}">
            <i class="bi bi-megaphone" aria-hidden="true"></i> Campaigns
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.contacts', $channel ? ['channel' => $channel] : []) }}">
            <i class="bi bi-people" aria-hidden="true"></i> Contacts
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.reports', $channel ? ['channel' => $channel] : []) }}">
            <i class="bi bi-graph-up" aria-hidden="true"></i> Cost vs revenue
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Messages" :value="$total" icon="bi-envelope-paper" hero
              hint="Written by a campaign — skipped recipients are not here, because nothing was handed over for them" />
    <x-ui.kpi label="Queued" :value="$counts['queued'] ?? 0" icon="bi-send"
              hint="A real transport took them; no confirmation has come back" />
    <x-ui.kpi label="Held" :value="$counts['not_configured'] ?? 0" icon="bi-pause-circle"
              :hint="($counts['not_configured'] ?? 0) > 0 ? 'No transport is configured for their channel — not a delivery' : 'Every message found a transport'" />
    <x-ui.kpi label="Sent" :value="$counts['sent'] ?? 0" icon="bi-check2-all"
              hint="A transport confirmed these after the fact" />
    <x-ui.kpi label="Failed" :value="$counts['failed'] ?? 0" icon="bi-exclamation-triangle"
              :hint="($counts['failed'] ?? 0) > 0 ? 'The provider said no — read the error on each row' : 'No provider has refused a message'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('marketing.deliveries') }}">
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
        <label class="form-label" for="state">State</label>
        <select class="form-select" name="state" id="state" data-erp-autosubmit>
            <option value="">Every state</option>
            @foreach ($states as $key => $label)
                <option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="q">Recipient or wording</label>
        <input class="form-control erp-filter-wide" type="search" name="q" id="q" value="{{ $search }}" placeholder="phone, address or a word from the body">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        @if ($channel || $state || $search !== '')
            <a class="btn btn-link" href="{{ route('marketing.deliveries') }}">Reset</a>
        @endif
    </div>
</form>

<x-ui.table-shell title="The outbox" :count="$messages->total().' message(s)'" stack="true">
    <thead>
        <tr>
            <th>Recipient</th>
            <th>Channel</th>
            <th>Campaign</th>
            <th>State</th>
            <th>When</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($messages as $message)
            <tr>
                <td data-label="Recipient">
                    <span class="erp-cell-strong">{{ $names[$message->id] ?? $message->recipient }}</span>
                    <div class="erp-td-muted">{{ $message->recipient }}</div>
                    @if ($message->subject)
                        <div class="erp-td-muted">{{ $message->subject }}</div>
                    @endif
                </td>
                <td data-label="Channel">
                    {{ $channels[$message->channel]['label'] ?? ucfirst($message->channel) }}
                    @if ($message->provider_code)
                        <div class="erp-td-muted">via {{ $message->provider_code }}</div>
                    @endif
                </td>
                <td data-label="Campaign">
                    @if ($message->campaign)
                        <a href="{{ route('marketing.campaigns.show', $message->campaign) }}">{{ $message->campaign->code }}</a>
                        <div class="erp-td-muted">{{ $message->campaign->name }}</div>
                    @else
                        <span class="erp-td-muted">—</span>
                    @endif
                </td>
                <td data-label="State">
                    <x-ui.status :value="$message->status" :label="$stateLabels[$message->status] ?? ucfirst($message->status)" />
                    @if ($message->status === 'not_configured')
                        <div class="erp-td-muted">No {{ strtolower($channels[$message->channel]['label'] ?? $message->channel) }} transport is configured — held, and it will stay held until one is.</div>
                    @endif
                    @if ($message->last_error)
                        <div class="erp-td-muted">{{ $message->last_error }}</div>
                    @endif
                </td>
                <td data-label="When">
                    {{ $message->status === 'sent' ? 'sent ' : 'queued ' }}{{ ($message->sent_at ?? $message->queued_at)?->format('d M Y H:i') ?? $message->created_at?->format('d M Y H:i') }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty
                        title="Nothing in the outbox on this filter"
                        text="Only recipients a campaign actually handed over appear here. Skipped recipients — opted out, blacklisted, or with no address — are rows on the campaign itself, with the reason written on them."
                        icon="bi-envelope-paper" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($messages->hasPages())
        <x-slot:footer>{{ $messages->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<div class="erp-note mt-3">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <div>
        <strong>Why “held” is on this page at all.</strong>
        WhatsApp and push campaigns can be written, costed and launched today — and every one of their messages will sit here as held, because this application has no WhatsApp sender and no push service. Saying so is the point: an outbox that silently dropped them, or dressed them up as sent, would make the campaign report a work of fiction.
    </div>
</div>

<x-ui.related-pages />
