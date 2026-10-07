<?php

namespace App\Domain\Inventory\Services;

use InvalidArgumentException;

/**
 * Code 128 encoder (§04-13, §04-52, §04-53).
 *
 * The barcode is emitted as SVG, not as a raster image: a label printed from
 * this application has to stay sharp at whatever size the paper asks for, and
 * SVG scales without the blur that turns a 0.3 mm bar into a grey smear at the
 * scanner's edge. No image library is installed and none is needed.
 *
 * Three decisions worth stating, because a barcode that is *nearly* right is
 * worse than no barcode at all — it scans at the desk and fails at the gate:
 *
 *  · **The pattern table is Code 128's, in full** (103 symbols, 0–102, plus the
 *    two start codes we can reach and the stop symbol). Each pattern is six
 *    widths — bar, space, bar, space, bar, space — summing to 11 modules, which
 *    is what makes the printed width predictable from the data length. The
 *    index comment on every row lets the slice audit check that no pattern has
 *    drifted out of position.
 *  · **Subset C is used only when it is exact, subset B otherwise.** An
 *    even-length run of digits encodes two digits per symbol in C, at half the
 *    width of B. An odd-length run stays in B rather than being padded with a
 *    zero: the padding would print on the label and the scanner would hand the
 *    padded value back as if it were the number we were given.
 *  · **The check character is computed, never accepted as input** (start code
 *    plus each symbol weighted by its position, mod 103). Callers pass the
 *    value; the symbol guards itself, and the screens show the check digit.
 *
 * The quiet zones are part of the output rather than the caller's problem: a
 * symbol printed without its 10-module light margin is refused by a good many
 * scanners, and a label that is already on the box cannot be corrected.
 */
class BarcodeService
{
    /** The 103 symbol patterns (values 0–102): six widths per row, each row summing to 11. */
    private const PATTERNS = [
        '212222', // 0
        '222122', // 1
        '222221', // 2
        '121223', // 3
        '121322', // 4
        '131222', // 5
        '122213', // 6
        '122312', // 7
        '132212', // 8
        '221213', // 9
        '221312', // 10
        '231212', // 11
        '112232', // 12
        '122132', // 13
        '122231', // 14
        '113222', // 15
        '123122', // 16
        '123221', // 17
        '223211', // 18
        '221132', // 19
        '221231', // 20
        '213212', // 21
        '223112', // 22
        '312131', // 23
        '311222', // 24
        '321122', // 25
        '321221', // 26
        '312212', // 27
        '322112', // 28
        '322211', // 29
        '212123', // 30
        '212321', // 31
        '232121', // 32
        '111323', // 33
        '131123', // 34
        '131321', // 35
        '112313', // 36
        '132113', // 37
        '132311', // 38
        '211313', // 39
        '231113', // 40
        '231311', // 41
        '112133', // 42
        '112331', // 43
        '132131', // 44
        '113123', // 45
        '113321', // 46
        '133121', // 47
        '313121', // 48
        '211331', // 49
        '231131', // 50
        '213113', // 51
        '213311', // 52
        '213131', // 53
        '311123', // 54
        '311321', // 55
        '331121', // 56
        '312113', // 57
        '312311', // 58
        '332111', // 59
        '314111', // 60
        '221411', // 61
        '431111', // 62
        '111224', // 63
        '111422', // 64
        '121124', // 65
        '121421', // 66
        '141122', // 67
        '141221', // 68
        '112214', // 69
        '112412', // 70
        '122114', // 71
        '122411', // 72
        '142112', // 73
        '142211', // 74
        '241211', // 75
        '221114', // 76
        '413111', // 77
        '241112', // 78
        '134111', // 79
        '111242', // 80
        '121142', // 81
        '121241', // 82
        '114212', // 83
        '124112', // 84
        '124211', // 85
        '411212', // 86
        '421112', // 87
        '421211', // 88
        '212141', // 89
        '214121', // 90
        '412121', // 91
        '111143', // 92
        '111341', // 93
        '131141', // 94
        '114113', // 95
        '114311', // 96
        '411113', // 97
        '411311', // 98
        '113141', // 99
        '114131', // 100
        '311141', // 101
        '411131', // 102
    ];

    /** Start C — the subset that takes two digits per symbol. */
    private const START_C = 105;

    /** Start B — the subset that takes one printable character per symbol. */
    private const START_B = 104;

    /** Start C's widths: reference value 105. */
    private const START_C_PATTERN = '211232';

    /** Start B's widths: reference value 104. */
    private const START_B_PATTERN = '211214';

    /** The stop symbol's widths: 11 modules plus the closing 2-module bar. */
    private const STOP_PATTERN = '2331112';

    /** Light margin each side of the bars, in modules. */
    public const QUIET_ZONE_MODULES = 10;

    /** The finest bar Code 128 may be printed at, in millimetres (ISO/IEC 15417 nominal). */
    public const MIN_MODULE_MM = 0.25;

    /**
     * The symbol and what it is made of.
     *
     * @return array{value: string, symbology: string, subset: string, start: int, checksum: int, symbols: int, modules: int, bars: string, symbols_mm: float}
     */
    public function encode(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('A barcode needs a value — an empty symbol would print as nothing and scan as nothing.');
        }

        $subset = $this->subset($value);
        $start = $subset === 'C' ? self::START_C : self::START_B;
        $data = $subset === 'C' ? $this->digitPairs($value) : $this->characters($value);

        $weighted = $start;

        foreach ($data as $index => $symbol) {
            $weighted += $symbol * ($index + 1);
        }

        $checksum = $weighted % 103;
        $pattern = '';

        foreach (array_merge([$start], $data, [$checksum, 106]) as $symbol) {
            $pattern .= $this->runs($symbol);
        }

        return [
            'value' => $value,
            'symbology' => 'Code 128',
            'subset' => $subset,
            'start' => $start,
            'checksum' => $checksum,
            'symbols' => count($data) + 3,   // start + data + check + stop
            'modules' => strlen($pattern),
            'bars' => $pattern,
        ];
    }

    /**
     * Printed width in millimetres at a given module size, quiet zones included.
     * The label desk uses this to refuse a code that would print too narrow.
     */
    public function widthMm(string $value, float $moduleMm): float
    {
        $encoded = $this->encode($value);

        return round(($encoded['modules'] + 2 * self::QUIET_ZONE_MODULES) * $moduleMm, 3);
    }

    /**
     * The symbol as SVG.
     *
     * @param  array{moduleWidth?: float, height?: float, showText?: bool, title?: string, class?: string}  $options
     * @return array{svg: string, meta: array<string, mixed>}
     */
    public function svg(string $value, array $options = []): array
    {
        $encoded = $this->encode($value);

        $moduleWidth = (float) ($options['moduleWidth'] ?? 2);
        $barsHeight = (float) ($options['height'] ?? 64);
        $showText = (bool) ($options['showText'] ?? true);
        $class = (string) ($options['class'] ?? 'erp-barcode');
        $title = (string) ($options['title'] ?? $value);

        $quiet = self::QUIET_ZONE_MODULES;
        $modules = $encoded['modules'] + 2 * $quiet;
        $width = $modules * $moduleWidth;
        $textSize = max(9.0, round($barsHeight * 0.22, 1));
        $height = $showText ? $barsHeight + $textSize + 4 : $barsHeight;

        $pattern = str_repeat('0', $quiet).$encoded['bars'].str_repeat('0', $quiet);
        $bars = '';
        $offset = 0;
        $length = strlen($pattern);

        // One rect per run of dark modules rather than one per module: fewer
        // nodes in a sheet that may carry a hundred labels, and the integer
        // edges stay crisp at print resolution.
        while ($offset < $length) {
            if ($pattern[$offset] === '0') {
                $offset++;

                continue;
            }

            $end = $offset;

            while ($end < $length && $pattern[$end] === '1') {
                $end++;
            }

            $bars .= sprintf(
                '<rect x="%s" y="0" width="%s" height="%s" />',
                $this->num($offset * $moduleWidth),
                $this->num(($end - $offset) * $moduleWidth),
                $this->num($barsHeight),
            );

            $offset = $end;
        }

        $text = $showText
            ? sprintf(
                '<text x="%s" y="%s" text-anchor="middle" font-family="DejaVu Sans Mono, monospace" font-size="%s" fill="#111">%s</text>',
                $this->num($width / 2),
                $this->num($height - 2),
                $this->num($textSize),
                $this->escape($value),
            )
            : '';

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="%s" role="img" width="%s" height="%s" viewBox="0 0 %s %s" shape-rendering="crispEdges" aria-label="%s"><title>%s</title><rect x="0" y="0" width="%s" height="%s" fill="#fff"/>%s%s</svg>',
            $this->escape($class),
            $this->num($width),
            $this->num($height),
            $this->num($width),
            $this->num($height),
            $this->escape($encoded['symbology'].' '.$value),
            $this->escape($title),
            $this->num($width),
            $this->num($height),
            $bars,
            $text,
        );

        return [
            'svg' => $svg,
            'meta' => $encoded + [
                'module_width' => $moduleWidth,
                'width' => $width,
                'height' => $height,
                'quiet_zone' => $quiet,
            ],
        ];
    }

    /** C when the value is an even run of digits (two per symbol), B otherwise. */
    protected function subset(string $value): string
    {
        if (preg_match('/^[0-9]+$/', $value) === 1 && strlen($value) % 2 === 0) {
            return 'C';
        }

        if (preg_match('/^[\x20-\x7e]+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Code 128 subset B prints printable ASCII only, so "%s" cannot be encoded as it stands. Encode the value a scanner should hand back — a code, a number, a short name — rather than the sentence itself.',
                $value,
            ));
        }

        return 'B';
    }

    /** @return array<int, int> */
    protected function digitPairs(string $value): array
    {
        $symbols = [];

        for ($i = 0; $i < strlen($value); $i += 2) {
            $symbols[] = (int) substr($value, $i, 2);
        }

        return $symbols;
    }

    /** @return array<int, int> */
    protected function characters(string $value): array
    {
        $symbols = [];

        for ($i = 0; $i < strlen($value); $i++) {
            $symbols[] = ord($value[$i]) - 32;
        }

        return $symbols;
    }

    /** A symbol's widths as a run of modules: dark, light, dark, light, dark, light. */
    protected function runs(int $symbol): string
    {
        $widths = match (true) {
            $symbol === 106 => self::STOP_PATTERN,
            $symbol === self::START_C => self::START_C_PATTERN,
            $symbol === self::START_B => self::START_B_PATTERN,
            default => $this->pattern($symbol),
        };

        $pattern = '';

        for ($i = 0; $i < strlen($widths); $i++) {
            $pattern .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $widths[$i]);
        }

        return $pattern;
    }

    protected function pattern(int $symbol): string
    {
        if (! isset(self::PATTERNS[$symbol])) {
            throw new InvalidArgumentException(sprintf('Code 128 has no symbol %d.', $symbol));
        }

        return self::PATTERNS[$symbol];
    }

    protected function num(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
