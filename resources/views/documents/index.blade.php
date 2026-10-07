@extends('layouts.app')

@section('page_title', 'Documents')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Documents</h1>
            <p class="erp-page-sub">Safe storage: extension allow-list, real MIME sniffing, per-type size limits, checksummed and audited on every access.</p>
        </div>
    </div>

    @if ($perm('documents.upload'))
        <section class="erp-card mb-3">
            <header class="erp-card-head"><h2 class="erp-card-title">Upload a document</h2></header>
            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label" for="file">File <span class="text-danger">*</span></label>
                        <input class="form-control @error('file') is-invalid @enderror" type="file" id="file" name="file" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="purpose">Purpose</label>
                        <select class="form-select" id="purpose" name="purpose">
                            @foreach (['attachment', 'logo', 'seal', 'signature', 'generated', 'export'] as $p)
                                <option value="{{ $p }}" @selected(old('purpose', $purpose ?: 'attachment') === $p)>{{ ucfirst($p) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-upload" aria-hidden="true"></i> Upload
                        </button>
                        <span class="form-text ms-2 d-inline">
                            max {{ round(config('erp.upload.max_bytes') / 1048576) }} MB
                        </span>
                    </div>
                </div>
            </form>
        </section>
    @endif

    <form class="row g-2 mb-3" method="GET" action="{{ route('documents.index') }}">
        <div class="col-sm-5 col-md-4">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search file name…" aria-label="Search documents">
        </div>
        <div class="col-sm-4 col-md-3">
            <select class="form-select" name="purpose" aria-label="Filter by purpose">
                <option value="">Any purpose</option>
                @foreach (['attachment', 'logo', 'seal', 'signature', 'generated', 'export'] as $p)
                    <option value="{{ $p }}" @selected($purpose === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q || $purpose)
            <div class="col-auto"><a class="btn btn-link" href="{{ route('documents.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Type</th>
                        <th class="text-end">Size</th>
                        <th>Purpose</th>
                        <th>Uploaded</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $document)
                        <tr>
                            <td>
                                <i class="bi bi-file-earmark me-1" aria-hidden="true"></i>
                                <span class="fw-semibold">{{ $document->original_name }}</span>
                            </td>
                            <td class="text-body-secondary small">{{ $document->mime_type }}</td>
                            <td class="text-end">{{ number_format($document->size_bytes / 1024, 1) }} KB</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $document->purpose ?: 'attachment' }}</span></td>
                            <td class="text-body-secondary small">
                                {{ $document->uploadedBy?->name ?: '—' }}<br>{{ $document->created_at?->format('d M Y, H:i') }}
                            </td>
                            <td class="text-end">
                                @if ($perm('documents.download'))
                                    <a class="btn btn-sm btn-light" href="{{ route('documents.download', $document) }}">
                                        <i class="bi bi-download" aria-hidden="true"></i> Download
                                    </a>
                                @endif
                                @if ($perm('documents.manage'))
                                    <form class="d-inline" method="POST" action="{{ route('documents.destroy', $document) }}"
                                          data-confirm="Delete {{ $document->original_name }}? The stored bytes are removed too. This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-body-secondary">No documents match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $documents->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
