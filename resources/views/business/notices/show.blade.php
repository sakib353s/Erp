<x-ui.page-header
    :eyebrow="'Business Management · Notice board · '.$notice->categoryLabel()"
    :title="$notice->title"
    :subtitle="'Published '.optional($notice->published_at)->format('d M Y, H:i').' by '.($notice->creator?->name ?? 'the system')"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Board
        </a>
        @if ($canTrack)
            <a class="btn btn-outline-secondary" href="{{ route('notices.tracking') }}">
                <i class="bi bi-clipboard2-check" aria-hidden="true"></i> Tracking
            </a>
        @endif
        @if ($notice->status === 'draft' && $canTrack)
            <form method="POST" action="{{ route('notices.publish', $notice) }}" data-confirm="Publish this notice to its audience?">
                @csrf
                <button class="btn btn-primary" type="submit"><i class="bi bi-megaphone" aria-hidden="true"></i> Publish</button>
            </form>
        @endif
        @if ($notice->status === 'published' && $canTrack)
            <form method="POST" action="{{ route('notices.archive', $notice) }}" data-confirm="Archive this notice? It leaves the board and stays on the register.">
                @csrf
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-archive" aria-hidden="true"></i> Archive</button>
            </form>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The notice</h2>
                    <p class="erp-card-sub">{{ $audienceLabel }} · <x-ui.status :value="$notice->status" :label="\App\Domain\Business\Notice::STATUSES[$notice->status] ?? $notice->status" /></p>
                </div>
            </header>
            <div class="p-3">
                {!! nl2br(e($notice->body)) !!}
            </div>
        </section>

        @if ($notice->requires_acknowledgement)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Acknowledgement</h2>
                        <p class="erp-card-sub">
                            {{ $ledger['acknowledged'] }} of {{ $ledger['audience'] }} acknowledged
                            @if ($ledger['pending'] > 0) · {{ $ledger['pending'] }} still to read it @endif
                        </p>
                    </div>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-soft">{{ $ledger['percent'] }}%</span>
                    </div>
                </header>
                <div class="p-3 pt-0">
                    <div class="erp-progress mb-3"><span style="width: {{ min(100, $ledger['percent']) }}%"></span></div>

                    @if ($acknowledged)
                        <p class="erp-note mb-0">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            You acknowledged this notice. The record is against your name, not against this browser.
                        </p>
                    @else
                        <form method="POST" action="{{ route('notices.acknowledge', $notice) }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label" for="note">Note (optional)</label>
                                    <input class="form-control" type="text" name="note" id="note" maxlength="255"
                                           placeholder="anything you want on the record beside your name">
                                </div>
                                <div class="col-md-4">
                                    <button class="btn btn-primary w-100" type="submit">
                                        <i class="bi bi-pen" aria-hidden="true"></i> I have read this
                                    </button>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        <div class="mt-3">
            <x-ui.related-pages />
        </div>
    </div>

    <div class="col-lg-4">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Facts</h2>
            </header>
            <dl class="erp-dl erp-dl-tight">
                <dt>Category</dt>
                <dd>{{ $notice->categoryLabel() }}</dd>
                <dt>Audience</dt>
                <dd>{{ $audienceLabel }}</dd>
                <dt>State</dt>
                <dd>{{ \App\Domain\Business\Notice::STATUSES[$notice->status] ?? $notice->status }}</dd>
                <dt>Published</dt>
                <dd>{{ optional($notice->published_at)->format('d M Y, H:i') ?? 'not yet' }}</dd>
                <dt>Expires</dt>
                <dd>{{ optional($notice->expires_at)->format('d M Y') ?? 'never' }}</dd>
                <dt>Acknowledgement</dt>
                <dd>{{ $notice->requires_acknowledgement ? 'asked for' : 'not asked' }}</dd>
            </dl>
        </section>

        @if ($canTrack)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">The ledger</h2>
                        <p class="erp-card-sub">Who has and who has not — the audience as it stands, plus anybody who acknowledged before the roster moved.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr><th>Person</th><th>When</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger['rows'] as $row)
                                <tr>
                                    <td>{{ $row['user']->name }}</td>
                                    <td class="erp-td-muted">
                                        @if ($row['acknowledgement'])
                                            {{ $row['acknowledgement']->acknowledged_at?->format('d M Y, H:i') }}
                                        @else
                                            <span class="erp-chip erp-chip-warn">waiting</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @foreach ($ledger['extra'] as $row)
                                <tr>
                                    <td>{{ $row->user?->name ?? 'a person who left' }}</td>
                                    <td class="erp-td-muted">
                                        {{ $row->acknowledged_at?->format('d M Y, H:i') }}
                                        <div class="erp-td-muted">acknowledged earlier — no longer in this audience</div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</div>
