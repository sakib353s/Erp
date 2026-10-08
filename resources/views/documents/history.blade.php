@extends('layouts.app')

@section('page_title', $companyLog ? 'Print & download log' : 'Print history — '.$label)

@section('content')
    <div class="erp-page-head">
        <div>
            <p class="erp-eyebrow mb-1">§16-25</p>
            <h1 class="erp-h1 mb-1">{{ $companyLog ? 'Print & download log' : 'Print history' }}</h1>
            <p class="erp-page-sub mb-0">
                @if ($companyLog)
                    Every document this company has produced or downloaded, newest first — with the title that
                    appeared on the paper, the paper size, the copy locale, any watermark and the checksum of the
                    bytes that were filed.
                @else
                    {{ $label }}@if ($subject) — {{ $subject }}@endif.
                    Each row is one copy that came off a printer or was saved as a file.
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-dark" href="{{ route('documents.print.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Printing
            </a>
        </div>
    </div>

    @if ($companyLog)
        <form class="erp-filterbar row g-2 align-items-end mb-3" method="GET" action="{{ route('documents.print.log') }}">
            <div class="col-sm-4 col-md-3">
                <label class="form-label" for="type">Document type</label>
                <input class="form-control" type="search" id="type" name="type" value="{{ request('type') }}" placeholder="invoice, delivery_challan…">
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="{{ request('from') }}">
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="{{ request('to') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            </div>
            @if (request()->query() !== [])
                <div class="col-auto"><a class="btn btn-link" href="{{ route('documents.print.log') }}">Reset</a></div>
            @endif
        </form>
    @endif

    <div class="erp-card">
        @if ($rows->isEmpty())
            <x-ui.empty title="Nothing printed yet"
                        text="When somebody prints or downloads this document, the copy is recorded here with who did it and what it said."
                        icon="bi-clock-history" />
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            @if ($companyLog)
                                <th>Document type</th>
                                <th class="text-end">Subject</th>
                            @endif
                            <th>Title on paper</th>
                            <th>Paper</th>
                            <th>Copy</th>
                            <th>By</th>
                            <th>Address</th>
                            <th>Filed copy</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row->created_at?->format('d M Y H:i') }}</td>
                                @if ($companyLog)
                                    <td class="fw-semibold">{{ $row->documentType?->name ?? $row->printable_type }}</td>
                                    <td class="text-end erp-page-sub">
                                        @if (str_starts_with((string) $row->printable_type, 'report:'))
                                            company-wide
                                        @else
                                            {{ class_basename($row->printable_type) }} #{{ $row->printable_id }}
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $row->printed_title ?? '—' }}</td>
                                <td>{{ strtoupper($row->page_format) }}</td>
                                <td>
                                    {{ $row->locale === 'bn' ? 'বাংলা' : 'English' }}
                                    @if ($row->watermark)
                                        <span class="erp-chip erp-chip-warn ms-1">{{ $row->watermark }}</span>
                                    @endif
                                    @if ($row->copies > 1)
                                        <span class="erp-page-sub ms-1">×{{ $row->copies }}</span>
                                    @endif
                                </td>
                                <td>{{ $row->user?->name ?? 'system' }}</td>
                                <td class="erp-page-sub">{{ $row->ip ?? '—' }}</td>
                                <td class="erp-page-sub">
                                    @if ($row->document)
                                        <a href="{{ route('documents.show', $row->document) }}" class="erp-page-sub">
                                            v{{ $row->document->version }} · {{ substr((string) $row->checksum, 0, 10) }}…
                                        </a>
                                    @else
                                        {{ $row->checksum ? substr((string) $row->checksum, 0, 10).'…' : '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 pb-3 erp-page-sub">
                Showing {{ $rows->count() }} of {{ $total }} {{ $total === 1 ? 'entry' : 'entries' }}.
            </div>
        @endif
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
