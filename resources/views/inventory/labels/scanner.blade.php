@extends('layouts.app')

@section('page_title', 'Scanner test')

{{-- The keystroke capture in resources/js/app.js listens on the body attribute, the
     same hook the POS terminal uses: a scanner firing with focus anywhere on the
     page still lands in this box, instead of scrolling the page sideways. --}}
@section('body_attrs') data-erp-scan-target="code" @endsection

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Labels & barcodes"
        title="Does the counter's scanner read our labels?"
        subtitle="Every handheld in this trade behaves like a keyboard: it types the code and presses Enter. This bench takes that exact input, resolves it through the same lookup the product screens use, and says what it found — so a scanner that is misfiring is found here, not at the counter with a customer waiting."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.index') }}">
                <i class="bi bi-printer" aria-hidden="true"></i> Label desk
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.barcodes') }}">
                <i class="bi bi-upc-scan" aria-hidden="true"></i> Barcode
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Point the scanner here and pull the trigger</h2>
                </header>

                <form method="GET" action="{{ route('inventory.labels.scanner') }}" role="search">
                    <label class="form-label" for="code">Scanned code</label>
                    <div class="erp-input-group">
                        <i class="bi bi-upc-scan" aria-hidden="true"></i>
                        <input class="form-control form-control-lg font-monospace" type="text" id="code" name="code"
                               value="{{ $scan }}" autocomplete="off" inputmode="text"
                               placeholder="Scan into this box, or type and press Enter"
                               minlength="{{ $settings['scan_min_length'] }}"
                               @if ($settings['scan_autofocus']) autofocus @endif>
                    </div>
                    <div class="form-text">
                        {{ $settings['scan_mode'] === 'keyboard_wedge' ? 'Keyboard-wedge scanners type into the focused field and send Enter, which is what this form expects.' : 'This bench reads keyboard-wedge input; the configured mode is "'.$settings['scan_mode'].'".' }}
                        Codes shorter than {{ $settings['scan_min_length'] }} character(s) are treated as a misread. A scan
                        fired with focus anywhere on this page is captured, exactly as the till captures it.
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-search" aria-hidden="true"></i> Resolve
                        </button>
                        @if ($scan !== null && $scan !== '')
                            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.scanner') }}">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            @if ($scan !== null && $scan !== '')
                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">What the code resolved to</h2>
                        <div class="erp-card-actions">
                            <span class="font-monospace">{{ $scan }}</span>
                        </div>
                    </header>

                    @if (mb_strlen($scan) < $settings['scan_min_length'])
                        <div class="erp-note erp-note-warn">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <div>
                                That is {{ mb_strlen($scan) }} character(s), under the
                                {{ $settings['scan_min_length'] }}-character floor in settings — most likely half a
                                barcode rather than a code. Point the scanner squarer at the bars and try again.
                            </div>
                        </div>
                    @elseif ($resolution && $resolution['found'])
                        <div class="erp-note erp-note-info mb-3">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            <div>
                                Matched the {{ $resolution['matched_on'] }} of
                                <strong>{{ $resolution['label'] }}</strong>
                                @if ($resolution['kind'] === 'product') ({{ $resolution['code'] }})@endif.
                                {{ $resolution['detail'] }}
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-secondary" href="{{ $resolution['href'] }}">
                                <i class="bi bi-upc-scan" aria-hidden="true"></i> Show the symbol
                            </a>
                            @if ($resolution['kind'] === 'batch')
                                <a class="btn btn-outline-secondary" href="{{ route('inventory.batches.index') }}">
                                    <i class="bi bi-layers" aria-hidden="true"></i> Batch register
                                </a>
                            @endif
                            <a class="btn btn-outline-secondary" href="{{ route('inventory.labels.scanner') }}">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Scan another
                            </a>
                        </div>
                    @else
                        <div class="erp-note erp-note-danger mb-3">
                            <i class="bi bi-x-octagon" aria-hidden="true"></i>
                            <div>
                                {{ $resolution['message'] ?? 'Nothing carries that code.' }}
                                Nothing was changed — a code that resolves to nothing is a printed label that has
                                to be fixed at the source, not a row to guess at.
                            </div>
                        </div>

                        <ul class="mb-0 ps-3">
                            <li>Check the label was printed by this company: the lookup is scoped to it.</li>
                            <li>
                                Codes are matched against the product's <strong>barcode</strong>, its
                                <strong>SKU</strong> and its <strong>code</strong> — if none of the three is what
                                the label carries, the product's barcode field is wrong.
                            </li>
                            <li>Batch labels carry the lot number, not the product's.</li>
                        </ul>
                    @endif
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">How the counters are configured</h2>
                    <div class="erp-card-actions">
                        @if ($perm('settings.view'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('settings.show', ['group' => 'barcode']) }}">
                                <i class="bi bi-sliders" aria-hidden="true"></i> Barcode settings
                            </a>
                        @endif
                    </div>
                </header>

                <dl class="erp-dl erp-dl-tight erp-dl-striped">
                    <dt>Input mode</dt>
                    <dd>{{ $settings['scan_mode'] === 'keyboard_wedge' ? 'Keyboard wedge — the scanner types the code and presses Enter' : $settings['scan_mode'] }}</dd>

                    <dt>Shortest code</dt>
                    <dd>{{ $settings['scan_min_length'] }} character(s) — anything shorter is treated as a misread</dd>

                    <dt>Two reads, one code</dt>
                    <dd>
                        {{ $settings['scan_clear_ms'] }} ms between keystrokes is one read; a longer pause starts a
                        new code. This is what stops two quick scans becoming one long nonsense code.
                    </dd>

                    <dt>Beep on a good read</dt>
                    <dd>{{ $settings['scan_beep'] ? 'On — the audio confirmation the counters expect' : 'Off' }}</dd>

                    <dt>Focus on load</dt>
                    <dd>{{ $settings['scan_autofocus'] ? 'On — the box is ready without a click' : 'Off' }}</dd>
                </dl>

                <p class="text-muted mb-0 mt-2">
                    These are the counter's settings, not this page's: they live in
                    Settings ▸ Barcode &amp; scanning and are read by the till and the desk alike. This bench
                    reads them so it exercises the same contract the counter does.
                </p>
            </div>

            <div class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Papers in use</h2>
                </header>

                <dl class="erp-dl erp-dl-tight erp-dl-striped">
                    @foreach ($templates as $key => $option)
                        <dt>{{ $option['short'] }}</dt>
                        <dd>
                            {{ $option['label'] }}
                            <span class="d-block text-muted">{{ $option['note'] }}</span>
                        </dd>
                    @endforeach
                </dl>
            </div>

            @if ($recent->isNotEmpty())
                <div class="erp-card mt-3">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Last sheets filed</h2>
                    </header>
                    <ul class="mb-0 ps-3">
                        @foreach ($recent as $sheet)
                            <li>
                                <a href="{{ route('inventory.labels.sheet', ['labelSheet' => $sheet->id]) }}">{{ $sheet->original_name }}</a>
                                <span class="text-muted">— {{ $sheet->created_at?->format('d M, H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <x-ui.related-pages />
@endsection
