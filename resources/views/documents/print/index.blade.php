@extends('layouts.app')

@section('page_title', 'Printing')

@section('content')
    <div class="erp-page-head">
        <div>
            <p class="erp-eyebrow mb-1">§16-23 · §16-24 · §16-25</p>
            <h1 class="erp-h1 mb-1">Printing</h1>
            <p class="erp-page-sub mb-0">
                One renderer, twelve document types. The title on the paper comes from the document-type rule,
                never from the template; the copy is filed with its checksum; and whoever produced it is recorded.
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($mayReadHistory)
                <a class="btn btn-outline-dark" href="{{ route('documents.print.log') }}">
                    <i class="bi bi-clock-history" aria-hidden="true"></i> Print &amp; download log
                </a>
            @endif
        </div>
    </div>

    @php
        $available = $types->where('available', true);
        $mayPrint = $available->where('may', true)->count();
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <x-ui.kpi label="Document types" :value="$available->count()" icon="bi-file-earmark-text"
                      hint="Each one prints through the same shell" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.kpi label="You may print" :value="$mayPrint" icon="bi-printer"
                      hint="Keys are per document, not per screen" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.kpi label="Recently produced" :value="$recent->count()" icon="bi-clock-history"
                      hint="Newest first, company-wide" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-ui.kpi label="Not printable yet" :value="$types->where('available', false)->count()" icon="bi-slash-circle"
                      hint="Listed with the reason, not hidden" />
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Title on paper</th>
                        <th>Paper</th>
                        <th>Where its documents live</th>
                        <th>Access needed</th>
                        <th class="text-end">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $type)
                        <tr>
                            <td class="fw-semibold">{{ $type['label'] }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $type['title'] }}</span></td>
                            <td class="erp-page-sub">
                                {{ $type['papers'] === [] ? '—' : collect($type['papers'])->map(fn ($p) => $p === 'a4' ? 'A4' : '80 mm')->join(' · ') }}
                            </td>
                            <td class="erp-page-sub">{{ $type['where'] ?? $type['reason'] }}</td>
                            <td>
                                @if ($type['permission'])
                                    <code class="erp-page-sub">{{ $type['permission'] }}</code>
                                @else
                                    <span class="erp-page-sub">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if (! $type['available'])
                                    <x-ui.status value="not_configured" label="No engine yet" />
                                @elseif ($type['may'])
                                    <x-ui.status value="active" label="Ready" />
                                @else
                                    <x-ui.status value="pending" label="Not for you" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="erp-note">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Printing starts from the document itself.</strong>
            <p class="mb-0">
                Open an invoice, a challan or a journal entry and print it from there — the paper is rendered,
                filed with its checksum and recorded against that document. A ledger or a statement is printed
                from the account or the party, over a period it states on its face.
                Reading <em>who</em> produced a copy, on what paper and with which checksum is a separate job
                and needs its own key — <code>documents.view_history</code>.
            </p>
        </div>
    </div>

    <section class="erp-card mt-3">
        <header class="erp-card-head d-flex justify-content-between align-items-center">
            <h2 class="erp-card-title mb-0">Recently produced</h2>
            @if ($mayReadHistory)
                <a class="btn btn-sm btn-light" href="{{ route('documents.print.log') }}">See everything</a>
            @endif
        </header>
        @if ($recent->isEmpty())
            <x-ui.empty title="Nothing has been printed yet"
                        text="The first document anybody prints or downloads appears here with who did it, on what paper and with which checksum."
                        icon="bi-printer" />
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Document</th>
                            <th>Title</th>
                            <th>Paper</th>
                            <th>Copy</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $row)
                            <tr>
                                <td>{{ $row->created_at?->format('d M Y H:i') }}</td>
                                <td class="fw-semibold">{{ $row->documentType?->name ?? '—' }}</td>
                                <td>{{ $row->printed_title ?? '—' }}</td>
                                <td>{{ strtoupper($row->page_format) }}</td>
                                <td>{{ $row->locale === 'bn' ? 'বাংলা' : 'English' }}{{ $row->watermark ? ' · '.$row->watermark : '' }}</td>
                                <td>{{ $row->user?->name ?? 'system' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
