<x-ui.page-header
    eyebrow="Business Management · Notice board"
    title="Who has not read it yet"
    subtitle="Every live notice that asks for an acknowledgement, with its tally. The figures come from each notice's own ledger, so this page and the notice's page can never disagree — and a name here is a name, not a count of emails that were sent.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Board
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('notices.register') }}">
            <i class="bi bi-card-list" aria-hidden="true"></i> Register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.table-shell title="Acknowledgement tracking" :count="count($rows).' notice(s) asking'">
    <thead>
        <tr>
            <th>Notice</th>
            <th>Published</th>
            <th class="erp-th-num">Audience</th>
            <th class="erp-th-num">Acknowledged</th>
            <th class="erp-th-num">Pending</th>
            <th>Progress</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <a class="erp-cell-strong" href="{{ route('notices.show', $row['notice']) }}">{{ $row['notice']->title }}</a>
                    <div class="erp-td-muted">{{ $row['notice']->categoryLabel() }}</div>
                </td>
                <td class="erp-td-muted">{{ optional($row['notice']->published_at)->format('d M Y') }}</td>
                <td class="erp-td-num">{{ $row['audience'] }}</td>
                <td class="erp-td-num">{{ $row['acknowledged'] }}</td>
                <td class="erp-td-num">
                    @if ($row['pending'] > 0)
                        <span class="erp-chip erp-chip-warn">{{ $row['pending'] }}</span>
                    @else
                        <span class="erp-chip erp-chip-ok">complete</span>
                    @endif
                </td>
                <td style="min-width:140px">
                    <div class="erp-progress"><span style="width: {{ min(100, $row['percent']) }}%"></span></div>
                    <div class="erp-td-muted">{{ $row['percent'] }}%</div>
                </td>
                <td class="text-end">
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('notices.show', $row['notice']) }}">
                        Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty icon="bi-clipboard2-check" title="No live notice is asking for an acknowledgement"
                                text="When a notice is published with “ask everybody to acknowledge it” switched on, it appears here with its tally until it expires or is archived." />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>
