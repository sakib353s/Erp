@php
    /* §11 — what the marketing cost, and what the recipients actually bought. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $rows = $performance['campaigns'];
    $totals = $performance['totals'];
    $byChannel = $rows->groupBy(fn (array $row) => $row['campaign']->channel);
    $best = $rows->filter(fn (array $row) => $row['roas'] !== null)->sortByDesc('roas')->first();
    $worst = $rows->filter(fn (array $row) => $row['cost'] > 0)->sortBy('roas')->first();
    $unlaunched = $rows->filter(fn (array $row) => ! $row['campaign']->isLaunched());
@endphp

<x-ui.page-header
    eyebrow="Marketing · Reports"
    title="What it cost, and what came back"
    subtitle="Cost is the message price frozen on each recipient when the campaign went out, so a campaign re-priced next month cannot rewrite last month's send. Revenue is the invoices those same recipients actually raised inside their attribution window — counted from the invoices themselves, never from a click nobody in this application recorded. A campaign that has not launched has no revenue line because it has not written to anybody yet; it says so rather than showing an encouraging zero."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.index') }}">
            <i class="bi bi-megaphone" aria-hidden="true"></i> Campaigns
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.optouts') }}">
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Opt-outs
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('reports.index') }}">
            <i class="bi bi-clipboard-data" aria-hidden="true"></i> Report centre
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Campaigns" :value="$totals['campaigns']" icon="bi-megaphone" hero
              :hint="$overview['launched'].' launched · '.$overview['drafts'].' draft · '.$overview['scheduled'].' on the clock'" />
    <x-ui.kpi label="Recipients" :value="$totals['recipients']" icon="bi-people"
              :hint="$overview['skipped_opted_out'].' skipped because they opted out'" />
    <x-ui.kpi label="Message cost" :value="$money($totals['cost'])" icon="bi-cash-stack" />
    <x-ui.kpi label="Invoices in window" :value="$totals['orders']" icon="bi-receipt" />
    <x-ui.kpi label="Revenue in window" :value="$money($totals['revenue'])" icon="bi-graph-up-arrow"
              hint="From the recipients of these campaigns, inside each campaign's own window" />
    <x-ui.kpi label="Best so far"
              :value="$best !== null ? $money($best['revenue']) : '—'"
              icon="bi-trophy"
              :hint="$best !== null
                    ? $best['campaign']->code.' · '.$best['roas'].'× the cost'
                    : 'No launched campaign has been costed yet'" />
</div>

@if ($totals['cost'] > 0 && $totals['revenue'] > 0)
    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-calculator" aria-hidden="true"></i>
        <div>
            <strong>{{ number_format($totals['revenue'] / max(1, $totals['cost']), 2) }}× across every campaign combined.</strong>
            @if ($worst !== null && $worst['roas'] !== null)
                The weakest is {{ $worst['campaign']->code }} at {{ $worst['roas'] }}× — worth reading before its next send.
            @endif
            Read the ratio with the delivery states beside it: a campaign whose messages are still
            <em>held</em> has not reached anybody yet, so a quiet window is not the same as a bad one.
        </div>
    </div>
@endif

@if ($unlaunched->isNotEmpty())
    <div class="erp-note mb-3">
        <i class="bi bi-hourglass" aria-hidden="true"></i>
        <div>
            <strong>{{ $unlaunched->count() }} campaign(s) have not gone out yet.</strong>
            They are counted in the campaign total and left out of the revenue figures, because a draft has no recipients to attribute anything to.
        </div>
    </div>
@endif

<x-ui.table-shell title="Channel by channel" :count="$byChannel->count().' channel(s) in use'" stack="true">
    <thead>
        <tr>
            <th>Channel</th>
            <th class="text-end">Campaigns</th>
            <th class="text-end">Recipients</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Invoices</th>
            <th class="text-end">Revenue</th>
            <th class="text-end">Ratio</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($byChannel as $channel => $channelRows)
            @php
                $cost = (float) $channelRows->sum('cost');
                $revenue = (float) $channelRows->sum('revenue');
            @endphp
            <tr>
                <td data-label="Channel">
                    <i class="bi {{ $channels[$channel]['icon'] ?? 'bi-megaphone' }} me-1" aria-hidden="true"></i>
                    {{ $channels[$channel]['label'] ?? ucfirst($channel) }}
                    @if (($channels[$channel]['contact'] ?? null) === 'phone')
                        <div class="erp-td-muted">written to the customer's phone</div>
                    @endif
                </td>
                <td data-label="Campaigns" class="erp-td-num text-end">{{ $channelRows->count() }}</td>
                <td data-label="Recipients" class="erp-td-num text-end">{{ (int) $channelRows->sum('recipients') }}</td>
                <td data-label="Cost" class="erp-td-num text-end">{{ $money($cost) }}</td>
                <td data-label="Invoices" class="erp-td-num text-end">{{ (int) $channelRows->sum('orders') }}</td>
                <td data-label="Revenue" class="erp-td-num text-end">{{ $money($revenue) }}</td>
                <td data-label="Ratio" class="erp-td-num text-end">{{ $cost > 0 ? number_format($revenue / $cost, 2).'×' : '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="No campaign has gone out yet"
                        text="This report reads the campaigns, the recipients they wrote rows for, and the invoices those recipients raised. It has nothing to read until the first campaign is launched."
                        icon="bi-graph-up" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<x-ui.table-shell title="Campaign by campaign" :count="$rows->count().' campaign(s)'" stack="true">
    <thead>
        <tr>
            <th>Campaign</th>
            <th>Channel</th>
            <th class="text-end">Recipients</th>
            <th class="text-end">Queued / held</th>
            <th class="text-end">Skipped</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Revenue</th>
            <th class="text-end">Ratio</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td data-label="Campaign">
                    <a class="erp-cell-strong" href="{{ route('marketing.campaigns.show', $row['campaign']) }}">{{ $row['campaign']->name }}</a>
                    <div class="erp-td-muted">
                        {{ $row['campaign']->code }} ·
                        @if ($row['campaign']->isLaunched())
                            launched {{ $row['campaign']->launched_at?->format('d M Y') }}, counting to {{ $row['campaign']->attributionEndsAt()?->format('d M Y') }}
                        @else
                            {{ strtolower($row['campaign']->stateLabel()) }} — nothing written to yet
                        @endif
                    </div>
                </td>
                <td data-label="Channel">{{ $row['campaign']->channelLabel() }}</td>
                <td data-label="Recipients" class="erp-td-num text-end">{{ $row['recipients'] }}</td>
                <td data-label="Queued / held" class="erp-td-num text-end">
                    {{ $row['queued'] }} / {{ $row['held'] }}
                    @if ($row['failed'] > 0)
                        <div class="erp-td-muted">{{ $row['failed'] }} failed</div>
                    @endif
                </td>
                <td data-label="Skipped" class="erp-td-num text-end">
                    {{ $row['recipients'] - $row['handed'] }}
                    @if ($row['skipped_opted_out'] > 0)
                        <div class="erp-td-muted">{{ $row['skipped_opted_out'] }} opted out</div>
                    @endif
                </td>
                <td data-label="Cost" class="erp-td-num text-end">{{ $money($row['cost']) }}</td>
                <td data-label="Revenue" class="erp-td-num text-end">
                    {{ $row['campaign']->isLaunched() ? $money($row['revenue']) : '—' }}
                </td>
                <td data-label="Ratio" class="erp-td-num text-end">
                    {{ $row['roas'] !== null ? $row['roas'].'×' : '—' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nothing to cost yet"
                        text="Every campaign written down is listed here with what it has cost and what it has brought in. Write the first one and it appears."
                        icon="bi-megaphone" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<div class="erp-note mt-3">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>What this report cannot tell you.</strong>
        It cannot say who opened a message, who read it, or which click led to which order — this application owns no tracking pixel and sends no tracking link, so it does not pretend to have measured either. What it does say is what was handed to a transport, what each message cost, and what the people written to actually bought afterwards — all of which you can check by opening the campaign and the customers.
    </div>
</div>

<x-ui.related-pages />
