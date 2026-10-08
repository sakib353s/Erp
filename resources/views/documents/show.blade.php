@extends('layouts.app')

@section('page_title', $document->original_name)

@section('content')
    @php
        // §16-21: the file's own page — what it is, whether the stored bytes are
        // really there, and the single address it has been published at.
        $linkState = $verification['published'] ? 'active' : ($verification['revoked_at'] ? 'cancelled' : 'not_configured');
        $linkLabel = $verification['published']
            ? ($verification['expires_at'] ? 'Live until '.$verification['expires_at']->format('d M Y') : 'Live')
            : ($verification['revoked_at'] ? 'Withdrawn' : ($verification['expired'] ? 'Expired' : 'Not shared'));
        $size = (int) $document->size_bytes;
        $human = $size >= 1048576
            ? number_format($size / 1048576, 2).' MB'
            : ($size >= 1024 ? number_format($size / 1024, 1).' KB' : $size.' bytes');
    @endphp

    <div class="erp-page-head">
        <div>
            <p class="erp-eyebrow mb-1">Document</p>
            <h1 class="erp-h1 mb-1">{{ $document->original_name }}</h1>
            <p class="erp-page-sub mb-0">
                {{ $human }} · {{ $document->mime_type }}
                @if ($document->uploader)
                    · stored by {{ $document->uploader->name }}
                @endif
                · {{ $document->created_at?->format('d M Y, H:i') }}
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <x-ui.status :value="$linkState" :label="$linkLabel" />
            @if ($perm('documents.download'))
                <a class="btn btn-outline-dark" href="{{ route('documents.download', $document) }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Download
                </a>
            @endif
        </div>
    </div>

    @unless ($stored)
        <div class="erp-note erp-note-danger">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>
                <strong>The stored file is missing from this system.</strong>
                <p class="mb-0">The row still exists, but nothing can be downloaded or published from it. Upload the file again; do not publish a link to a file that is not there.</p>
            </div>
        </div>
    @endunless

    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Public link</h2>

                @if ($verification['published'])
                    <label class="form-label" for="public-url">The address to send</label>
                    <input class="form-control" id="public-url" type="text" readonly value="{{ $verification['url'] }}">
                    <p class="erp-page-sub mt-2 mb-0">
                        Rotating it prints a new address and stops the old one working immediately. Withdrawing it closes the file to everybody.
                    </p>
                @elseif ($verification['revoked_at'])
                    <div class="erp-note erp-note-warn">
                        <i class="bi bi-link-45deg" aria-hidden="true"></i>
                        <div>
                            <strong>Withdrawn {{ $verification['revoked_at']->format('d M Y, H:i') }}.</strong>
                            <p class="mb-0">Anybody holding the old address now gets a 404. Publishing again issues a fresh one.</p>
                        </div>
                    </div>
                @elseif ($verification['expired'])
                    <div class="erp-note erp-note-warn">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <div>
                            <strong>The link expired{{ $verification['expires_at'] ? ' on '.$verification['expires_at']->format('d M Y') : '' }}.</strong>
                            <p class="mb-0">The file was never downloadable through it after that moment. Publish again — and leave the expiry empty if the link is meant to be permanent.</p>
                        </div>
                    </div>
                @else
                    <div class="erp-note">
                        <i class="bi bi-link" aria-hidden="true"></i>
                        <div>
                            <strong>This file is not shared.</strong>
                            <p class="mb-0">Publishing writes an address that opens this one document without a login. Leave the expiry empty for a permanent link, or give it a number of days to close on its own.</p>
                        </div>
                    </div>
                @endif

                @if ($perm('documents.manage') && $stored)
                    <div class="erp-form-actions mt-3">
                        @if ($verification['published'])
                            <a class="btn btn-outline-dark" target="_blank" rel="noopener" href="{{ $verification['url'] }}">
                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open the page
                            </a>
                            <form method="POST" action="{{ route('documents.share.rotate', $document) }}"
                                  data-confirm="Rotate the link? The address already sent out will stop working.">
                                @csrf
                                <button class="btn btn-outline-dark" type="submit">Rotate the link</button>
                            </form>
                            <form method="POST" action="{{ route('documents.share.revoke', $document) }}"
                                  data-confirm="Withdraw the link? Nobody will be able to open this file without a login.">
                                @csrf
                                <button class="btn btn-outline-danger" type="submit">Withdraw</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('documents.share.publish', $document) }}" class="row g-2 align-items-end">
                                @csrf
                                <div class="col-auto">
                                    <label class="form-label" for="expires_in_days">Closes after (days)</label>
                                    <input class="form-control" id="expires_in_days" name="expires_in_days" type="number"
                                           min="1" max="365" step="1" placeholder="never">
                                    @error('expires_in_days')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-primary" type="submit">Publish public link</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">How the link has been used</h2>
                <dl class="erp-dl erp-dl-tight mb-0">
                    <div><dt>Opens</dt><dd>{{ $verification['visits'] }}</dd></div>
                    <div><dt>Downloads</dt><dd>{{ $verification['downloads'] }}</dd></div>
                    <div><dt>Last opened</dt><dd>{{ $verification['last_seen_at'] ? \Illuminate\Support\Carbon::parse($verification['last_seen_at'])->format('d M Y, H:i') : 'never' }}</dd></div>
                    @if ($verification['last_ip'])
                        <div><dt>Last address</dt><dd>{{ $verification['last_ip'] }}</dd></div>
                    @endif
                    @if ($verification['issued_at'])
                        <div><dt>Published</dt><dd>{{ $verification['issued_at']->format('d M Y, H:i') }}</dd></div>
                    @endif
                    <div><dt>Rotation</dt><dd>{{ $verification['rotation'] }}</dd></div>
                </dl>
                <p class="erp-page-sub mb-0 mt-2">
                    Every open and every download is written to the access log and the audit trail with the address the visitor came from.
                </p>
            </div>

            <div class="erp-card mt-3">
                <h2 class="erp-h3 mb-3">The file</h2>
                <dl class="erp-dl erp-dl-tight mb-0">
                    <div><dt>Kind</dt><dd>{{ $document->documentType?->name ?? ucfirst((string) $document->purpose) }}</dd></div>
                    <div><dt>Size</dt><dd>{{ $human }}</dd></div>
                    @if ($document->checksum)
                        <div><dt>Checksum</dt><dd style="word-break:break-all">{{ substr((string) $document->checksum, 0, 24) }}…</dd></div>
                    @endif
                    @if ($document->branch)
                        <div><dt>Branch</dt><dd>{{ $document->branch->name }}</dd></div>
                    @endif
                    <div><dt>Stored on disk</dt><dd>{{ $stored ? 'yes' : 'missing' }}</dd></div>
                </dl>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
