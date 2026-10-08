@extends('layouts.app')

@section('page_title', 'Barcode')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Labels & barcodes"
        title="One barcode, shown honestly"
        subtitle="Code 128 — the symbology that carries letters as well as digits, which is what a product code needs. This screen encodes one string, shows exactly what the symbol says, and tells you whether it is wide enough to print on the paper you have."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.index') }}">
                <i class="bi bi-printer" aria-hidden="true"></i> Label desk
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.qr-codes') }}">
                <i class="bi bi-qr-code" aria-hidden="true"></i> QR code
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.scanner') }}">
                <i class="bi bi-broadcast" aria-hidden="true"></i> Scanner test
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.labels.barcodes') }}" role="search">
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
            <label class="form-label" for="code">Or any code</label>
            <input class="form-control font-monospace" type="text" id="code" name="code"
                   value="{{ request('code') }}" placeholder="PKD-BOX-12" autocomplete="off">
        </div>

        <div class="erp-filter">
            <label class="form-label" for="template">Label</label>
            <select class="form-select" id="template" name="template">
                @foreach ($templates as $key => $option)
                    <option value="{{ $key }}" @selected($template['key'] === $key)>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="erp-filter">
            <label class="form-label" for="module">Bar width (units)</label>
            <input class="form-control" type="number" step="0.5" min="0.5" max="8" id="module" name="module"
                   value="{{ request('module', 2) }}">
        </div>

        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-upc-scan" aria-hidden="true"></i> Encode</button>
        </div>
    </form>

    @error('templates')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror

    @if ($subject === null)
        <x-ui.empty icon="bi-upc-scan" title="Nothing to encode yet"
                    text="Pick a product, or type any code, and the symbol it would print as appears here with its check digit, its width and whether your paper can hold it." />
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
                               href="{{ route('inventory.products.barcode', ['product' => $subject['id']]) }}">
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
                        'kind' => 'barcode',
                        'encoded' => $encoded,
                        'payload' => $payload,
                        'fit' => $fit,
                        'template' => $template,
                    ])
                </div>

                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Where the value comes from</h2>
                    </header>
                    <p class="text-muted mb-0">
                        A product label carries the product's own <strong>barcode</strong> when it has one, and
                        falls back to its <strong>SKU</strong> and then its <strong>code</strong> — never to a
                        number invented at print time, because a label that encodes something the catalogue does
                        not know scans into nothing.
                    </p>
                </div>
            </aside>
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <div class="mt-3">
            <x-ui.table-shell :title="'Recently filed sheets'" :count="$recent->count()">
                <thead>
                    <tr><th>Sheet</th><th>Filed</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($recent as $sheet)
                        <tr>
                            <td data-label="Sheet">{{ $sheet->original_name }}</td>
                            <td data-label="Filed" class="erp-td-muted">{{ $sheet->created_at?->format('d M Y, H:i') }}</td>
                            <td data-label="">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('inventory.labels.sheet', ['labelSheet' => $sheet->id]) }}">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table-shell>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
