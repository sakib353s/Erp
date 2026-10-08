{{--
    What a generated symbol actually says, in numbers.

    Shared by the two generator screens so both answer the same questions the
    same way: what is encoded, how wide the symbol came out, whether that fits
    the paper, and — when it will not fit — by how much. Nothing here is
    estimated; every figure comes back from the encoder that drew the symbol.
--}}
@php
    $isQr = $kind === 'qr';
    $quiet = $encoded['quiet_zone'] ?? 0;
    $totalModules = $isQr ? ($encoded['size'] ?? 0) : (($encoded['modules'] ?? 0) + 2 * $quiet);
@endphp

<div class="erp-dl erp-dl-tight erp-dl-striped">
    <dt>Encoded</dt>
    <dd><span class="font-monospace">{{ $payload }}</span></dd>

    <dt>Length</dt>
    <dd>{{ mb_strlen((string) $payload) }} character(s) — {{ mb_strlen((string) $payload, '8bit') }} byte(s)</dd>

    @if ($isQr)
        <dt>Symbol</dt>
        <dd>QR · level {{ $encoded['level'] }} · version {{ $encoded['version'] }} ({{ $encoded['size'] }} × {{ $encoded['size'] }} modules)</dd>

        <dt>Mask</dt>
        <dd>{{ $encoded['mask'] }} — the least-worst of the eight patterns for this data, chosen by the standard's own penalty rules</dd>

        <dt>Fits in</dt>
        <dd>
            {{ $encoded['capacity'] }} byte(s) at this version and level —
            {{ $encoded['size'] }} × {{ $encoded['size'] }} modules of data, plus the
            {{ $quiet }}-module quiet zone: {{ $encoded['size'] + 2 * $quiet }} modules a side
        </dd>
    @else
        <dt>Symbol</dt>
        <dd>
            {{ $encoded['symbology'] }} · subset {{ $encoded['subset'] }} ·
            {{ $encoded['symbols'] }} symbols (start, {{ $encoded['symbols'] - 3 }} data, check, stop)
        </dd>

        <dt>Check digit</dt>
        <dd>
            {{ $encoded['checksum'] }} — weighted mod 103, so a scanner can tell a
            misread from a read
        </dd>

        <dt>Worth</dt>
        <dd>
            {{ $encoded['modules'] }} module(s) of bars, plus a {{ $quiet }}-module quiet zone
            at each end: {{ $totalModules }} modules across
        </dd>
    @endif
</div>

@if ($fit)
    <div class="erp-note {{ $fit['prints'] ? 'erp-note-info' : 'erp-note-warn' }} mt-3">
        <i class="bi {{ $fit['prints'] ? 'bi-check2-circle' : 'bi-rulers' }}" aria-hidden="true"></i>
        <div>
            @if ($fit['prints'])
                On {{ $template['label'] }} this prints at
                <strong>{{ number_format($fit['module_mm'], 3) }} mm</strong> per bar — above the
                {{ number_format($fit['minimum_mm'], 2) }} mm floor. A hand scanner reads it first time.
            @else
                On {{ $template['label'] }} this would print at
                <strong>{{ number_format($fit['module_mm'], 3) }} mm</strong> per bar, under the
                {{ number_format($fit['minimum_mm'], 2) }} mm floor. The code needs
                {{ number_format($fit['needed_mm'], 1) }} mm of clear width; this label leaves
                {{ number_format($fit['available_mm'], 1) }} mm.
                <span class="d-block mt-1">
                    Shorten what the label carries (a 4-digit code prints far wider than a 20-character one),
                    or use a wider paper.
                </span>
            @endif
        </div>
    </div>
@endif

@if ($isQr && $encoded)
    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            A QR carries the whole payload, so it grows with the string and not with the paper:
            this one is {{ $encoded['size'] + 2 * $quiet }} modules a side, drawn
            {{ $encoded['side'] }} units wide at {{ $encoded['module_size'] }} units per module.
            On a label the module is sized to the space the label leaves, because a QR that is
            squeezed below about 0.25 mm a module stops being readable.
        </div>
    </div>
@endif
