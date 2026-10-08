@extends('layouts.app')

@section('page_title', 'QR code')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Labels & barcodes"
        title="One QR code, with the whole payload"
        subtitle="A QR carries the string itself, in byte mode, so anything the label can say the code can say. Its version and its mask are not guesses: the encoder picks the smallest version the data fits in and the mask the standard's penalty rules score lowest."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.index') }}">
                <i class="bi bi-printer" aria-hidden="true"></i> Label desk
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.barcodes') }}">
                <i class="bi bi-upc-scan" aria-hidden="true"></i> Barcode
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.scanner') }}">
                <i class="bi bi-broadcast" aria-hidden="true"></i> Scanner test
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.labels.qr-codes') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="product">Product</label>
            <select class="form-select" id="product" name="product">
                <option value="">— type a code instead —</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected((int) request('product') === $product->id)>
                        {{ $product->name }} ({{ $product->code }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="erp-filter">
            <label class="form-label" for="code">Or any string</label>
            <input class="form-control font-monospace" type="text" id="code" name="code"
                   value="{{ request('code') }}" placeholder="INV-2026-00771 · ৳ 18,240" autocomplete="off">
        </div>

        <div class="erp-filter">
            <label class="form-label" for="level">Error correction</label>
            <select class="form-select" id="level" name="level">
                @foreach ($levels as $level)
                    <option value="{{ $level }}" @selected(request('level', $defaults['qr_level']) === $level)>
                        {{ $level }}{{ $level === 'L' ? ' — 7 % recoverable' : ($level === 'M' ? ' — 15 % (usual)' : ($level === 'Q' ? ' — 25 %' : ' — 30 %, survives scuffing')) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="erp-filter">
            <label class="form-label" for="module">Module size</label>
            <input class="form-control" type="number" step="1" min="1" max="20" id="module" name="module"
                   value="{{ request('module', 4) }}">
        </div>

        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-qr-code" aria-hidden="true"></i> Encode</button>
        </div>
    </form>

    @if ($subject === null)
        <x-ui.empty icon="bi-qr-code" title="Nothing to encode yet"
                    text="Pick a product, or type any string — a document number, a tracking reference, a whole address line — and the code for it appears here with its version, its mask and its size." />
    @elseif ($error)
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $error }}</div>
        </div>
    @else
        <div class="erp-split">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">The symbol</h2>
                    <div class="erp-card-actions">
                        @if (($subject['type'] ?? null) === 'product')
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('inventory.products.qr', ['product' => $subject['id'], 'level' => request('level', $defaults['qr_level'])]) }}">
                                <i class="bi bi-download" aria-hidden="true"></i> Save SVG
                            </a>
                        @endif
                    </div>
                </header>

                <div class="erp-label-preview">
                    {!! $symbol !!}
                </div>

                <p class="text-muted mb-0 mt-2">
                    {{ $subject['name'] ?? '' }}
                    @if (! empty($subject['subtitle']))· {{ $subject['subtitle'] }}@endif
                </p>
            </section>

            <aside>
                <div class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">What it says</h2>
                    </header>

                    @include('inventory.labels.partials.symbol-readout', [
                        'kind' => 'qr',
                        'encoded' => $encoded,
                        'payload' => $payload,
                        'fit' => $fit,
                        'template' => $template,
                    ])
                </div>

                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Choosing a level</h2>
                    </header>
                    <p class="text-muted mb-2">
                        Error correction is paid for in modules: the same string at level H is a bigger symbol
                        than at level L, and a bigger symbol needs a bigger label.
                    </p>
                    <ul class="mb-0 ps-3">
                        <li><strong>L / M</strong> — a warehouse label read by a fixed scanner at arm's length.</li>
                        <li><strong>Q / H</strong> — a label that travels, gets rained on, and is read by a phone camera.</li>
                    </ul>
                </div>
            </aside>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
