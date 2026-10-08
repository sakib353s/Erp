{{--
    §04-53 — the label sheet as it prints.

    Standalone HTML with its own styles, like every other generated document in
    this application: it is stored on disk, checksummed, and printed from a
    browser, so it cannot depend on the app layout, on a build step or on a CDN.
    Every measurement is in millimetres and comes from the template the operator
    chose, because a label sheet that is 2 mm out puts a whole row on a seam.

    The fit warnings the desk shows are deliberately *not* here: a warning
    printed on the paper would ruin the labels it is warning about.
--}}
@php
    $template = $sheet['template'];
    $page = $template['page_definition'];
    $perPage = $sheet['totals']['per_page'];
    $pages = array_chunk($sheet['labels'], $perPage);
    $isRoll = $template['page'] === 'roll';
    $rollWidth = $template['width_mm'] + 2 * $template['margin_left_mm'];
    $rollHeight = count($pages) === 0 ? 0 : count($pages[0]) * ($template['height_mm'] + $template['gap_y_mm']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Labels — {{ $sheet['totals']['labels'] }} on {{ $sheet['totals']['pages'] }} page(s)</title>
<style>
    @page {
        size: {{ $isRoll ? $rollWidth.'mm '.$rollHeight.'mm' : 'A4' }};
        margin: 0;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .erp-sheet-bar {
        padding: 6mm 0 3mm;
        font-size: 11px;
        color: #444;
        display: flex;
        justify-content: space-between;
        gap: 8mm;
    }

    .erp-sheet-page {
        position: relative;
        width: {{ $isRoll ? $rollWidth.'mm' : $page['width_mm'].'mm' }};
        height: {{ $isRoll ? $rollHeight.'mm' : $page['height_mm'].'mm' }};
        page-break-after: always;
        break-after: page;
        overflow: hidden;
    }

    .erp-sheet-page:last-of-type { page-break-after: auto; break-after: auto; }

    .erp-sheet-label {
        position: absolute;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 2mm 3mm;
        overflow: hidden;
        border: 0.2mm dashed #c9c9c9; /* the cut line: a guide for the eye, not the print */
    }

    .erp-sheet-firm {
        font-size: 6.5pt;
        line-height: 1.1;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .erp-sheet-name {
        font-size: 8pt;
        line-height: 1.15;
        font-weight: 700;
        max-height: 8mm;
        overflow: hidden;
    }

    .erp-sheet-symbol {
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: 1.5mm;
        margin: 0.8mm 0;
    }

    .erp-sheet-bars { flex: 0 0 auto; }
    .erp-sheet-bars svg { display: block; width: 100%; height: 100%; }
    .erp-sheet-qr { flex: 0 0 auto; }
    .erp-sheet-qr svg { display: block; width: 100%; height: 100%; }

    .erp-sheet-foot {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 2mm;
        font-size: 7pt;
        line-height: 1.1;
    }

    .erp-sheet-code {
        font-family: DejaVu Sans Mono, "Courier New", monospace;
        letter-spacing: 0.2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .erp-sheet-price { font-weight: 700; white-space: nowrap; }
    .erp-sheet-batch { font-size: 6.5pt; color: #333; }

    @media screen {
        body { background: #eef1f4; }
        .erp-sheet-page {
            margin: 0 auto 6mm;
            background: #fff;
            box-shadow: 0 1px 6px rgba(15, 23, 42, .18);
        }
    }
</style>
</head>
<body>
<div class="erp-sheet-bar">
    <span>
        {{ $sheet['totals']['labels'] }} label(s) · {{ $template['label'] }} ·
        {{ $sheet['totals']['pages'] }} page(s) · printed {{ $sheet['generated_at'] ?? now()->toDateTimeString() }}
    </span>
    <span>
        Code 128 · {{ number_format($sheet['totals']['narrowest_module_mm'], 2) }} mm per bar at the narrowest
        @if ($sheet['totals']['qr'])
            · QR level {{ $sheet['labels'][0]['qr_level'] ?? '' }}
        @endif
    </span>
</div>

@foreach ($pages as $pageIndex => $labels)
    <section class="erp-sheet-page">
        @foreach ($labels as $index => $label)
            @php
                $column = $isRoll ? 0 : $index % $template['columns'];
                $row = $isRoll ? $index : intdiv($index, $template['columns']);
                $left = $template['margin_left_mm'] + $column * ($template['width_mm'] + $template['gap_x_mm']);
                $top = $template['margin_top_mm'] + $row * ($template['height_mm'] + $template['gap_y_mm']);
            @endphp
            <div class="erp-sheet-label" style="left: {{ $left }}mm; top: {{ $top }}mm; width: {{ $template['width_mm'] }}mm; height: {{ $template['height_mm'] }}mm;">
                @if ($label['show_company'] && $label['company'])
                    <div class="erp-sheet-firm">{{ $label['company'] }}</div>
                @endif

                <div class="erp-sheet-name">{{ $label['name'] }}</div>

                <div class="erp-sheet-symbol">
                    <div class="erp-sheet-bars" style="width: {{ $label['bars_width_mm'] }}mm; height: {{ $label['bars_height_mm'] }}mm;">
                        {!! $label['barcode_svg'] !!}
                    </div>
                    @if ($label['qr_svg'])
                        <div class="erp-sheet-qr" style="width: {{ $label['qr_size_mm'] }}mm; height: {{ $label['qr_size_mm'] }}mm;">
                            {!! $label['qr_svg'] !!}
                        </div>
                    @endif
                </div>

                <div class="erp-sheet-foot">
                    @if ($label['show_code_text'])
                        <span class="erp-sheet-code">{{ $label['payload'] }}</span>
                    @endif
                    @if ($label['show_price'] && $label['price'] > 0)
                        <span class="erp-sheet-price">৳ {{ number_format((float) $label['price'], 2) }}{{ $label['unit'] ? ' / '.$label['unit'] : '' }}</span>
                    @endif
                </div>

                @if ($label['batch_no'] || $label['expires_on'])
                    <div class="erp-sheet-batch">
                        @if ($label['batch_no'])Batch {{ $label['batch_no'] }}@endif
                        @if ($label['expires_on'])· exp {{ \Illuminate\Support\Carbon::parse($label['expires_on'])->format('d M Y') }}@endif
                    </div>
                @endif
            </div>
        @endforeach
    </section>
@endforeach

<script>window.erpSheet = { labels: {{ $sheet['totals']['labels'] }}, pages: {{ $sheet['totals']['pages'] }} };</script>
</body>
</html>
