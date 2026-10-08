@extends('layouts.guest')

@section('page_title', 'Document · '.$document['name'])

@section('content')
    {{-- §16-21: one file, served to whoever holds the link. Nothing else in the
         workspace is reachable from here, and the page says so. --}}
    @php
        $size = (int) $document['size_bytes'];
        $human = $size >= 1048576
            ? number_format($size / 1048576, 2).' MB'
            : ($size >= 1024 ? number_format($size / 1024, 1).' KB' : $size.' bytes');
    @endphp

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
            <p class="erp-eyebrow mb-1">Shared document</p>
            <h1 class="erp-h1 mb-1">{{ $document['name'] }}</h1>
            <p class="erp-page-sub mb-0">
                {{ $document['company'] ?? 'This company' }}
                @if ($document['uploaded_at'])
                    · stored {{ $document['uploaded_at']->format('d M Y') }}
                @endif
            </p>
        </div>
        <span class="erp-status erp-status-active">Link live</span>
    </div>

    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
        <div>
            <strong>{{ $document['company'] ?? 'The company' }} shared this file with you.</strong>
            <p class="mb-0">
                It is the document itself — {{ $human }}, {{ $document['mime_type'] }}.
                @if ($document['expires_at'])
                    The link closes on {{ $document['expires_at']->format('d M Y, H:i') }}.
                @else
                    The company can withdraw or replace the link at any time.
                @endif
            </p>
        </div>
    </div>

    <div class="mt-3">
        <a class="btn btn-primary" href="{{ $document['download_url'] }}">
            <i class="bi bi-download" aria-hidden="true"></i> Download {{ $document['name'] }}
        </a>
    </div>

    <div class="erp-card mt-3">
        <h2 class="erp-h3 mb-3">What this file is</h2>
        <dl class="erp-dl erp-dl-tight mb-0">
            <div>
                <dt>File</dt>
                <dd>{{ $document['name'] }}</dd>
            </div>
            <div>
                <dt>Kind</dt>
                <dd>{{ $document['type'] ?? ucfirst($document['purpose']) }} (.{{ $document['extension'] ?? 'file' }})</dd>
            </div>
            <div>
                <dt>Size</dt>
                <dd>{{ $human }}</dd>
            </div>
            <div>
                <dt>Content type</dt>
                <dd>{{ $document['mime_type'] }}</dd>
            </div>
            @if ($document['checksum'])
                <div>
                    <dt>Checksum (SHA-256)</dt>
                    <dd style="word-break:break-all">{{ substr((string) $document['checksum'], 0, 32) }}…</dd>
                </div>
            @endif
            @if ($document['uploaded_at'])
                <div>
                    <dt>Stored</dt>
                    <dd>{{ $document['uploaded_at']->format('d M Y, H:i') }}</dd>
                </div>
            @endif
            @if ($document['expires_at'])
                <div>
                    <dt>Link closes</dt>
                    <dd>{{ $document['expires_at']->format('d M Y, H:i') }}</dd>
                </div>
            @endif
            <div>
                <dt>Checked</dt>
                <dd>{{ $document['checked_at']->format('d M Y, H:i') }}</dd>
            </div>
        </dl>
    </div>

    <div class="erp-note mt-3">
        <i class="bi bi-eye-slash" aria-hidden="true"></i>
        <div>
            <strong>What this page does not show</strong>
            <p class="mb-0">{{ $document['privacy_note'] }}</p>
        </div>
    </div>
@endsection
