@php
    /*
     * §16-23 — the printable shell every document type is rendered through.
     *
     * It is a standalone page on purpose: what reaches the printer must not
     * depend on the application's chrome, its scripts or its network. The logo,
     * the seal and the signature travel as data URIs for the same reason.
     *
     * Two papers, one body: A4 for the office and 80mm thermal for the counter,
     * because the same delivery challan is printed in both places and a second
     * template is a second set of mistakes.
     */
    $money = fn ($value) => $localization->number((float) $value, 2);
    $qty = fn ($value) => $localization->qty((float) $value);
    $cell = fn ($row, $column) => match ($column['type'] ?? 'text') {
        'money' => $money($row[$column['key']] ?? 0),
        'qty' => $qty($row[$column['key']] ?? 0),
        default => (string) ($row[$column['key']] ?? ''),
    };
    $label = function (string $text) use ($labels): string {
        $key = strtolower(trim($text));

        return $labels[$key] ?? $text;
    };
    $thermal = $page_format === 'thermal';
    $qr = $paper['qr'] ?? null;
    $rows = $paper['rows'] ?? [];
    // §16-24 (D10): a document that is not tax-applicable, or whose transaction
    // carries no tax, does not get a VAT column full of zeros — an empty tax
    // column reads like a charge that was considered and forgiven.
    $columns = collect($paper['columns'] ?? [])
        ->reject(fn ($column) => ! ($title['tax_block'] ?? false) && in_array(strtolower((string) $column['label']), ['vat', 'tax'], true))
        ->values()
        ->all();
    $grandTotal = collect($paper['totals'] ?? [])->firstWhere('strong', true)['value'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title['title'] }} {{ $paper['reference'] ?? '' }}</title>
    <style>
        :root {
            --ink: #111827;
            --muted: #4b5563;
            --line: #d1d5db;
            --soft: #f3f4f6;
            --accent: {{ $company?->brand_accent ?? '#00a97f' }};
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            background: #fff;
            font-family: -apple-system, "Segoe UI", Roboto, "Noto Sans Bengali", Arial, sans-serif;
            font-size: {{ $thermal ? '10px' : '12px' }};
            line-height: 1.45;
        }
        .sheet { width: {{ $thermal ? '80mm' : '210mm' }}; min-height: {{ $thermal ? 'auto' : '270mm' }}; margin: 0 auto; padding: {{ $thermal ? '3mm' : '6mm 8mm' }}; position: relative; }
        @page { size: {{ $thermal ? '80mm auto' : 'A4' }}; margin: {{ $thermal ? '2mm' : '12mm' }}; }

        .watermark {
            position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: {{ $thermal ? '34px' : '110px' }}; font-weight: 800; letter-spacing: .12em;
            color: rgba(17, 24, 39, .07); transform: rotate(-24deg); pointer-events: none; z-index: 0;
            text-transform: uppercase;
        }
        .stack { position: relative; z-index: 1; }

        header.head { display: flex; gap: 10px; justify-content: space-between; border-bottom: 2px solid var(--ink); padding-bottom: 8px; align-items: flex-start; }
        .brand { display: flex; gap: 10px; align-items: flex-start; }
        .brand img.logo { height: {{ $thermal ? '34px' : '52px' }}; width: auto; }
        .brand .name { font-size: {{ $thermal ? '13px' : '17px' }}; font-weight: 800; letter-spacing: -.01em; }
        .brand .meta { color: var(--muted); font-size: {{ $thermal ? '9px' : '11px' }}; }
        .title-block { text-align: right; }
        .title-block .doc-title { font-size: {{ $thermal ? '14px' : '20px' }}; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .title-block .ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; }
        .title-block .state { display: inline-block; margin-top: 4px; border: 1px solid var(--line); border-radius: 999px; padding: 1px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
        .statutory { margin-top: 4px; font-size: 10px; color: var(--muted); }

        .parties { display: flex; gap: 10px; margin-top: 10px; }
        .parties .box { flex: 1; border: 1px solid var(--line); border-radius: 6px; padding: 8px 10px; }
        .parties .box .label { text-transform: uppercase; letter-spacing: .08em; font-size: 9px; color: var(--muted); }
        .parties .box .who { font-weight: 700; margin-top: 2px; }
        .parties .box .line { color: var(--muted); font-size: 11px; }

        .meta-grid { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 8px; }
        .meta-grid .pair { min-width: 130px; }
        .meta-grid .k { display: block; text-transform: uppercase; letter-spacing: .06em; font-size: 9px; color: var(--muted); }
        .meta-grid .v { font-weight: 600; }

        table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lines thead { display: table-header-group; }
        table.lines th { text-align: left; text-transform: uppercase; letter-spacing: .06em; font-size: 9px; color: var(--muted); border-bottom: 1px solid var(--ink); padding: 5px 4px; }
        table.lines th.end, table.lines td.end { text-align: right; }
        table.lines td { padding: 5px 4px; border-bottom: 1px solid var(--soft); vertical-align: top; }
        table.lines tr { page-break-inside: avoid; break-inside: avoid; }
        table.lines tbody tr:nth-child(even) { background: #fcfcfd; }

        .bottom { display: flex; gap: 14px; margin-top: 12px; align-items: flex-start; }
        .bottom .totals { margin-left: auto; min-width: {{ $thermal ? '100%' : '62mm' }}; }
        .bottom .totals table { width: 100%; border-collapse: collapse; }
        .bottom .totals td { padding: 3px 4px; }
        .bottom .totals td.end { text-align: right; font-variant-numeric: tabular-nums; }
        .bottom .totals tr.strong td { border-top: 1px solid var(--ink); font-weight: 800; font-size: {{ $thermal ? '11px' : '13px' }}; }
        .bottom .totals tr.tax td { color: var(--muted); }

        .words { margin-top: 8px; font-style: italic; color: var(--muted); }
        .notes { margin-top: 10px; border-left: 3px solid var(--accent); padding-left: 8px; }
        .notes .k { text-transform: uppercase; letter-spacing: .06em; font-size: 9px; color: var(--muted); }
        .notes ul { margin: 3px 0 0; padding-left: 16px; }

        .signs { display: flex; gap: 18px; margin-top: 22px; align-items: flex-end; }
        .signs .who { flex: 1; border-top: 1px solid var(--ink); padding-top: 4px; font-size: 10px; color: var(--muted); text-align: center; }
        .signs img.sign { height: 34px; display: block; margin: 0 auto 2px; }
        .signs .seal { width: 76px; height: 76px; object-fit: contain; opacity: .85; }
        .qr { text-align: center; }
        .qr svg { width: {{ $thermal ? '84px' : '104px' }}; height: auto; }
        .qr .cap { font-size: 9px; color: var(--muted); max-width: 130px; margin: 2px auto 0; }

        footer.foot { margin-top: 12px; border-top: 1px solid var(--line); padding-top: 5px; color: var(--muted); font-size: 9px; display: flex; justify-content: space-between; gap: 8px; }
        .empty { border: 1px dashed var(--line); border-radius: 6px; padding: 10px; color: var(--muted); margin-top: 10px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <div class="stack">
        <header class="head">
            <div class="brand">
                @if ($brand['logo'])
                    <img class="logo" src="{{ $brand['logo'] }}" alt="">
                @endif
                <div>
                    <div class="name">{{ $company?->name ?? 'Company' }}</div>
                    <div class="meta">
                        @if ($company?->legal_name)<div>{{ $company->legal_name }}</div>@endif
                        <div>
                            @foreach (array_filter([$company?->address_line1, $company?->address_line2, $company?->area, $company?->district, $company?->postal_code]) as $part)
                                {{ $part }}@if (! $loop->last), @endif
                            @endforeach
                        </div>
                        <div>
                            @foreach (array_filter([$company?->phone, $company?->email, $company?->website]) as $part)
                                {{ $part }}@if (! $loop->last) · @endif
                            @endforeach
                        </div>
                        @if ($company?->tin || $company?->bin)
                            <div>
                                @if ($company?->tin) TIN {{ $company->tin }} @endif
                                @if ($company?->bin) BIN {{ $company->bin }} @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="title-block">
                <div class="doc-title">{{ $title['title'] }}</div>
                <div class="ref">{{ $paper['reference'] ?? '—' }}</div>
                @if ($paper['status'] ?? null)
                    <div class="state">{{ \Illuminate\Support\Str::headline((string) $paper['status']) }}</div>
                @endif
                @if ($title['statutory'])
                    <div class="statutory">Statutory form — printed separately from the commercial document.</div>
                @endif
                @if ($locale === 'bn')
                    <div class="statutory">বাংলা কপি</div>
                @endif
            </div>
        </header>

        <section class="parties">
            @if ($paper['party'] ?? null)
                <div class="box">
                    <div class="label">{{ $paper['party']['label'] ?? 'Party' }}</div>
                    <div class="who">{{ $paper['party']['name'] ?? '—' }}</div>
                    @foreach ($paper['party']['lines'] ?? [] as $line)
                        <div class="line">{{ $line }}</div>
                    @endforeach
                </div>
            @endif
            <div class="box">
                <div class="label">{{ $labels['date'] }}</div>
                <div class="who">{{ $localization->date($paper['date'] ?? null) ?: '—' }}</div>
                <div class="line">
                    {{ $labels['printed'] }} {{ $localization->date($generated_at, 'd M Y H:i') }}
                    {{ $labels['by'] }} {{ $printed_by->name }}
                </div>
            </div>
        </section>

        @if ($paper['meta'] ?? [])
            <section class="meta-grid">
                @foreach ($paper['meta'] as $row)
                    <div class="pair">
                        <span class="k">{{ $label((string) $row['label']) }}</span>
                        <span class="v">{{ $row['value'] }}</span>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($rows === [])
            <div class="empty">{{ $labels['no_rows'] }}</div>
        @else
            <table class="lines">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th class="{{ ($column['align'] ?? 'start') === 'end' ? 'end' : '' }}"
                                @if (! empty($column['width'])) style="width: {{ $column['width'] }}" @endif>
                                {{ $label((string) $column['label']) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($columns as $column)
                                <td class="{{ ($column['align'] ?? 'start') === 'end' ? 'end' : '' }}">{{ $cell($row, $column) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="bottom">
            <div class="qr">
                @if ($qr)
                    {!! $qr['svg'] !!}
                    <div class="cap">{{ $qr['label'] ?? '' }}</div>
                @endif
            </div>

            <div class="totals">
                <table>
                    @foreach ($paper['totals'] ?? [] as $line)
                        <tr class="{{ ($line['strong'] ?? false) ? 'strong' : '' }}">
                            <td>{{ $label((string) $line['label']) }}</td>
                            <td class="end">{{ ($line['type'] ?? 'money') === 'qty' ? $qty($line['value']) : $money($line['value']) }}</td>
                        </tr>
                    @endforeach

                    @if ($title['tax_block'] ?? false)
                        @foreach ($paper['tax_lines'] ?? [] as $line)
                            <tr class="tax">
                                <td>{{ $label((string) $line['label']) }}</td>
                                <td class="end">{{ $money($line['value']) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    @foreach ($paper['settlement'] ?? [] as $line)
                        <tr class="{{ ($line['strong'] ?? false) ? 'strong' : '' }}">
                            <td>{{ $label((string) $line['label']) }}</td>
                            <td class="end">{{ $money($line['value']) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>

        @if ($localization->amountWordsEnabled() && $grandTotal !== null)
            <div class="words">
                {{ $labels['in_words'] }}: {{ $localization->words((float) $grandTotal) }}
            </div>
        @endif

        @if ($paper['notes'] ?? [])
            <section class="notes">
                <span class="k">{{ $labels['notes'] }}</span>
                <ul>
                    @foreach ($paper['notes'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="signs">
            @if ($brand['seal'])
                <img class="seal" src="{{ $brand['seal'] }}" alt="">
            @endif
            <div class="who">
                @if ($brand['signature'])<img class="sign" src="{{ $brand['signature'] }}" alt="">@endif
                {{ $labels['signature'] }}
            </div>
            <div class="who">{{ $labels['received_by'] }}</div>
        </section>

        <footer class="foot">
            <span>
                {{ $paper['reference'] ?? '' }}
                · {{ $title['title'] }}
                · {{ strtoupper($page_format) }}
                @if ($watermark) · {{ strtoupper($watermark) }} @endif
            </span>
            <span>
                {{ $labels['printed'] }} {{ $localization->date($generated_at, 'd M Y H:i') }}
                · {{ $printed_by->name }}
            </span>
        </footer>
    </div>
</div>
</body>
</html>
