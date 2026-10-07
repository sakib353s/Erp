<?php

namespace App\Domain\Inventory\Services;

use InvalidArgumentException;

/**
 * QR encoder (§04-13, §04-52, §04-53).
 *
 * Written here rather than pulled in because no composer package could be
 * installed for it, and because the parts a QR needs are four small tables plus
 * arithmetic over GF(2^8/0x11D) — nothing that justifies a dependency in a
 * codebase that has to survive an upgrade. The tables are the QR Model 2
 * standard's, as published in nayuki's MIT-licensed reference implementation;
 * the algorithm (bit buffer, Reed–Solomon, masking with the standard penalty
 * rule) is the standard's, and the output has been read back with an
 * independent decoder rather than assumed correct.
 *
 * Decisions worth stating:
 *
 *  · **Byte mode, all four error-correction levels, every version.** A label
 *    carries codes and numbers, not Kanji; byte mode is what the payloads need
 *    and it means the encoder has one mode's quirks instead of four. The
 *    version is chosen as the smallest that fits, so a short product code stays
 *    a version 1 symbol (21×21) instead of a sparse 40.
 *  · **The mask is chosen by the standard's penalty rule**, not at random and
 *    not fixed: a QR printed on a label is read by a phone held at an angle,
 *    and the four penalty terms (long runs, blocks, finder-like patterns, dark
 *    balance) are exactly the rules that make such a symbol readable.
 *  · **SVG output**, because a label that is printed has to stay sharp at
 *    whatever size the paper asks for; `shape-rendering: crispEdges` keeps the
 *    module edges square instead of anti-aliased into grey.
 *
 * The payload a caller passes is encoded verbatim — this service never invents
 * a URL or a token, so what the screen shows as "encoded" is literally what a
 * scanner will hand back.
 */
class QrService
{
    /** Error-correction levels, in the order of the tables below. */
    public const LEVELS = ['L', 'M', 'Q', 'H'];

    /** The two-bit value each level carries in the format information. */
    private const FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** Light margin, in modules, that the standard asks for around a symbol. */
    public const QUIET_ZONE_MODULES = 4;

    /**
     * Reed–Solomon codewords per block, by level and version.
     * Index 0 of each row is padding (an illegal version) so the version can be
     * used as the index directly.
     */
    private const ECC_CODEWORDS_PER_BLOCK = [
        'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Number of error-correction blocks, by level and version. */
    private const NUM_BLOCKS = [
        'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** The standard's four penalty weights: runs, blocks, finder-likes, balance. */
    private const PENALTY_N1 = 3;

    private const PENALTY_N2 = 3;

    private const PENALTY_N3 = 40;

    private const PENALTY_N4 = 10;

    /**
     * Encode a payload into a QR symbol.
     *
     * @return array{data: string, level: string, version: int, size: int, mask: int, capacity: int, rows: array<int, string>}
     */
    public function encode(string $data, string $level = 'M', ?int $version = null, int $mask = -1): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('A QR code needs something to say — an empty symbol would scan as nothing.');
        }

        $level = strtoupper($level);

        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown error-correction level "%s" — the standard defines L, M, Q and H.', $level));
        }

        $version ??= $this->fitVersion(strlen($data), $level);

        if ($version < 1 || $version > 40) {
            throw new InvalidArgumentException(sprintf('QR version %d does not exist; versions run 1 to 40.', $version));
        }

        $capacity = $this->dataCodewords($version, $level);

        $payload = $this->bitStream($data, $version, $capacity);

        $codewords = $this->interleave($payload, $version, $level);

        [$rows, $functions] = $this->functionPatterns($version);

        $this->drawCodewords($rows, $functions, $codewords, $version);

        if ($mask === -1) {
            $best = 0;
            $lowest = PHP_INT_MAX;

            for ($candidate = 0; $candidate < 8; $candidate++) {
                $this->applyMask($rows, $functions, $candidate, $version);
                $this->drawFormatBits($rows, $functions, $level, $candidate, $version);
                $penalty = $this->penalty($rows, $version);

                if ($penalty < $lowest) {
                    $best = $candidate;
                    $lowest = $penalty;
                }

                $this->applyMask($rows, $functions, $candidate, $version); // XOR again undoes it
            }

            $mask = $best;
        }

        $this->applyMask($rows, $functions, $mask, $version);
        $this->drawFormatBits($rows, $functions, $level, $mask, $version);

        return [
            'data' => $data,
            'level' => $level,
            'version' => $version,
            'size' => $version * 4 + 17,
            'mask' => $mask,
            'capacity' => $capacity,
            'rows' => $rows,
        ];
    }

    /**
     * The symbol as SVG, quiet zone included.
     *
     * @param  array{moduleSize?: float, class?: string, title?: string}  $options
     * @return array{svg: string, meta: array<string, mixed>}
     */
    public function svg(string $data, array $options = []): array
    {
        $level = (string) ($options['level'] ?? 'M');
        $encoded = $this->encode($data, $level);

        $moduleSize = (float) ($options['moduleSize'] ?? 4);
        $class = (string) ($options['class'] ?? 'erp-qr');
        $title = (string) ($options['title'] ?? $data);
        $quiet = self::QUIET_ZONE_MODULES;

        $side = ($encoded['size'] + 2 * $quiet) * $moduleSize;

        $body = '';

        // One rect per run of dark modules in a row: a version 10 symbol would
        // otherwise be several thousand nodes for a hundred labels.
        foreach ($encoded['rows'] as $y => $row) {
            $offset = 0;
            $length = strlen($row);

            while ($offset < $length) {
                if ($row[$offset] === '0') {
                    $offset++;

                    continue;
                }

                $end = $offset;

                while ($end < $length && $row[$end] === '1') {
                    $end++;
                }

                $body .= sprintf(
                    '<rect x="%s" y="%s" width="%s" height="%s"/>',
                    $this->num(($offset + $quiet) * $moduleSize),
                    $this->num(($y + $quiet) * $moduleSize),
                    $this->num(($end - $offset) * $moduleSize),
                    $this->num($moduleSize),
                );

                $offset = $end;
            }
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="%s" role="img" width="%s" height="%s" viewBox="0 0 %s %s" shape-rendering="crispEdges" aria-label="%s"><title>%s</title><rect x="0" y="0" width="%s" height="%s" fill="#fff"/><g fill="#000">%s</g></svg>',
            $this->escape($class),
            $this->num($side),
            $this->num($side),
            $this->num($side),
            $this->num($side),
            $this->escape('QR '.$encoded['level'].' version '.$encoded['version']),
            $this->escape($title),
            $this->num($side),
            $this->num($side),
            $body,
        );

        return [
            'svg' => $svg,
            'meta' => $encoded + [
                'module_size' => $moduleSize,
                'side' => $side,
                'quiet_zone' => $quiet,
            ],
        ];
    }

    /** The smallest version whose data capacity holds this many bytes. */
    public function fitVersion(int $bytes, string $level = 'M'): int
    {
        for ($version = 1; $version <= 40; $version++) {
            $bits = 4 + ($version <= 9 ? 8 : 16) + $bytes * 8; // mode + character count + data

            if ($this->dataCodewords($version, $level) * 8 >= $bits) {
                return $version;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'A %d-byte payload does not fit in any QR symbol (the largest, version 40 at level L, holds 2953 bytes). Split the payload or encode a document number instead of the document.',
            $bytes,
        ));
    }

    /** Data codewords available in a version at a level. */
    public function dataCodewords(int $version, string $level): int
    {
        return intdiv($this->rawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$level][$version] * self::NUM_BLOCKS[$level][$version];
    }

    /** Modules left for data once every function pattern is drawn. */
    protected function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    /** Mode indicator, character count, payload bytes, terminator and padding. */
    protected function bitStream(string $data, int $version, int $capacity): string
    {
        $bits = '0100';                                              // byte mode
        $bits .= str_pad(decbin(strlen($data)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        $bits .= $this->bytesToBits($data);

        $remaining = $capacity * 8 - strlen($bits);

        if ($remaining < 0) {
            throw new InvalidArgumentException(sprintf(
                'A %d-byte payload does not fit in a version %d symbol — the symbol was chosen for it, so this is a defect rather than a size problem.',
                strlen($data),
                $version,
            ));
        }

        $bits .= str_repeat('0', min(4, $remaining));                 // terminator
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);        // to the byte boundary

        // Then the standard's alternating pad bytes until the capacity is full.
        $pad = 0;

        while (strlen($bits) < $capacity * 8) {
            $bits .= $pad % 2 === 0 ? '11101100' : '00010001';
            $pad++;
        }

        return $bits;
    }

    protected function bytesToBits(string $data): string
    {
        $bits = '';

        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    /**
     * Split the payload into blocks, append Reed–Solomon codewords to each and
     * interleave them the way the standard prescribes.
     */
    protected function interleave(string $bits, int $version, string $level): string
    {
        $blocks = self::NUM_BLOCKS[$level][$version];
        $eccPerBlock = self::ECC_CODEWORDS_PER_BLOCK[$level][$version];
        $rawCodewords = intdiv($this->rawDataModules($version), 8);
        $shortBlocks = $blocks - $rawCodewords % $blocks;
        $shortBlockLength = intdiv($rawCodewords, $blocks);

        $codewords = [];

        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        $divisor = $this->reedSolomonDivisor($eccPerBlock);
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        for ($i = 0; $i < $blocks; $i++) {
            $length = $shortBlockLength - $eccPerBlock + ($i < $shortBlocks ? 0 : 1);
            $block = array_slice($codewords, $offset, $length);
            $offset += $length;

            $eccBlocks[] = $this->reedSolomonRemainder($block, $divisor);
            $dataBlocks[] = $block;
        }

        $result = [];

        for ($i = 0; $i < $shortBlockLength - $eccPerBlock + 1; $i++) {
            for ($j = 0; $j < $blocks; $j++) {
                if ($i < count($dataBlocks[$j])) {
                    $result[] = $dataBlocks[$j][$i];
                }
            }
        }

        for ($i = 0; $i < $eccPerBlock; $i++) {
            for ($j = 0; $j < $blocks; $j++) {
                $result[] = $eccBlocks[$j][$i];
            }
        }

        $out = '';

        foreach ($result as $codeword) {
            $out .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * The generator polynomial for Reed–Solomon over GF(2^8/0x11D), highest
     * power first, leading 1 dropped.
     *
     * @return array<int, int>
     */
    protected function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = $this->multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * The error-correction codewords for one block.
     *
     * @param  array<int, int>  $data
     * @param  array<int, int>  $divisor
     * @return array<int, int>
     */
    protected function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /** Multiplication in GF(2^8/0x11D), by the usual shift-and-reduce. */
    protected function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /**
     * Draw everything that is not data: finders, timing, alignment, version
     * information and a placeholder for the format bits.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function functionPatterns(int $version): array
    {
        $size = $version * 4 + 17;
        $rows = array_fill(0, $size, str_repeat('0', $size));
        $functions = array_fill(0, $size, str_repeat('0', $size));

        // Timing patterns
        for ($i = 0; $i < $size; $i++) {
            $rows[6][$i] = $i % 2 === 0 ? '1' : '0';
            $rows[$i][6] = $i % 2 === 0 ? '1' : '0';
            $functions[6][$i] = '1';
            $functions[$i][6] = '1';
        }

        // Finder patterns with their separators (the 9×9 block around each corner)
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;

                    if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
                        continue;
                    }

                    $dark = max(abs($dx), abs($dy)) !== 2 && max(abs($dx), abs($dy)) !== 4;
                    $rows[$y][$x] = $dark ? '1' : '0';
                    $functions[$y][$x] = '1';
                }
            }
        }

        // Alignment patterns, everywhere except the three finder corners
        $positions = $this->alignmentPositions($version);
        $count = count($positions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $dark = max(abs($dx), abs($dy)) !== 1;
                        $rows[$positions[$j] + $dy][$positions[$i] + $dx] = $dark ? '1' : '0';
                        $functions[$positions[$j] + $dy][$positions[$i] + $dx] = '1';
                    }
                }
            }
        }

        $this->drawFormatBits($rows, $functions, 'M', 0, $version);
        $this->drawVersionBits($rows, $functions, $version);

        return [$rows, $functions];
    }

    /**
     * The alignment-pattern centres for a version.
     *
     * @return array<int, int>
     */
    protected function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $size = $version * 4 + 17;
        $count = intdiv($version, 7) + 2;
        $step = intdiv($version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;

        $result = [6];

        for ($i = $count - 2; $i >= 0; $i--) {
            $result[] = $size - 7 - $i * $step;
        }

        sort($result);

        return array_values(array_unique($result));
    }

    /**
     * Walk the two-module columns in the standard's zigzag and drop the
     * codeword bits into every module that is not a function module.
     *
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $functions
     */
    protected function drawCodewords(array &$rows, array $functions, string $bits, int $version): void
    {
        $size = $version * 4 + 17;
        $index = 0;
        $total = strlen($bits);

        // The pair index walks down in twos; the *column* it points at shifts
        // one to the left once the walk reaches the vertical timing column (x=6),
        // which is how that column is skipped rather than drawn over. The shift
        // cannot be written back into the loop variable — doing that makes the
        // walk jump between 5 and 3 for ever.
        for ($pair = $size - 1; $pair >= 1; $pair -= 2) {
            $right = $pair <= 6 ? $pair - 1 : $pair;

            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vertical : $vertical;

                    if ($functions[$y][$x] === '0' && $index < $total) {
                        $rows[$y][$x] = $bits[$index];
                        $index++;
                    }
                }
            }
        }
    }

    /**
     * XOR the mask over every module that is not a function module. Calling it
     * twice with the same mask removes it again, which is how the mask search
     * below undoes each candidate.
     *
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $functions
     */
    protected function applyMask(array &$rows, array $functions, int $mask, int $version): void
    {
        $size = $version * 4 + 17;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($functions[$y][$x] === '1' || ! $this->maskApplies($mask, $x, $y)) {
                    continue;
                }

                $rows[$y][$x] = $rows[$y][$x] === '1' ? '0' : '1';
            }
        }
    }

    /** The eight mask conditions of the standard. */
    protected function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
            default => throw new InvalidArgumentException(sprintf('Mask %d does not exist; the standard defines 0 to 7.', $mask)),
        };
    }

    /**
     * The 15 format bits (level and mask, BCH-protected and masked with 101010000010010)
     * written to both copies of the format area.
     *
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $functions
     */
    protected function drawFormatBits(array &$rows, array &$functions, string $level, int $mask, int $version): void
    {
        $size = $version * 4 + 17;
        $data = self::FORMAT_BITS[$level] << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i < 6; $i++) {
            $this->set($rows, $functions, 8, $i, $bits, $i);
        }

        $this->set($rows, $functions, 8, 7, $bits, 6);
        $this->set($rows, $functions, 8, 8, $bits, 7);
        $this->set($rows, $functions, 7, 8, $bits, 8);

        for ($i = 9; $i < 15; $i++) {
            $this->set($rows, $functions, 14 - $i, 8, $bits, $i);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set($rows, $functions, $size - 1 - $i, 8, $bits, $i);
        }

        for ($i = 8; $i < 15; $i++) {
            $this->set($rows, $functions, 8, $size - 15 + $i, $bits, $i);
        }

        // The one module that is always dark
        $rows[$size - 8][8] = '1';
        $functions[$size - 8][8] = '1';
    }

    /**
     * The 18 version bits (BCH-protected), written for version 7 and above.
     *
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $functions
     */
    protected function drawVersionBits(array &$rows, array &$functions, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $size = $version * 4 + 17;
        $remainder = $version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = $version << 12 | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1 ? '1' : '0';
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);

            $rows[$b][$a] = $bit;
            $functions[$b][$a] = '1';
            $rows[$a][$b] = $bit;
            $functions[$a][$b] = '1';
        }
    }

    /**
     * One module of the format area, marked as a function module.
     *
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $functions
     */
    protected function set(array &$rows, array &$functions, int $x, int $y, int $bits, int $index): void
    {
        $rows[$y][$x] = (($bits >> $index) & 1) === 1 ? '1' : '0';
        $functions[$y][$x] = '1';
    }

    /**
     * The standard's penalty score for a finished candidate symbol: how hard
     * the four rule sets say it will be to read. Lower is better.
     *
     * @param  array<int, string>  $rows
     */
    protected function penalty(array $rows, int $version): int
    {
        $size = $version * 4 + 17;
        $result = 0;

        foreach ([false, true] as $columns) {
            for ($outer = 0; $outer < $size; $outer++) {
                $runColor = false;
                $runLength = 0;
                $history = [0, 0, 0, 0, 0, 0, 0];

                for ($inner = 0; $inner < $size; $inner++) {
                    $module = $columns ? $rows[$inner][$outer] : $rows[$outer][$inner];
                    $dark = $module === '1';

                    if ($dark === $runColor) {
                        $runLength++;

                        if ($runLength === 5) {
                            $result += self::PENALTY_N1;
                        } elseif ($runLength > 5) {
                            $result++;
                        }

                        continue;
                    }

                    $this->addRun($runLength, $history, $size);

                    if (! $runColor) {
                        $result += $this->countFinderLike($history) * self::PENALTY_N3;
                    }

                    $runColor = $dark;
                    $runLength = 1;
                }

                $result += $this->terminateRun($runColor, $runLength, $history, $size) * self::PENALTY_N3;
            }
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($rows[$y][$x] === $rows[$y][$x + 1] && $rows[$y][$x] === $rows[$y + 1][$x] && $rows[$y][$x] === $rows[$y + 1][$x + 1]) {
                    $result += self::PENALTY_N2;
                }
            }
        }

        $dark = 0;

        foreach ($rows as $row) {
            $dark += substr_count($row, '1');
        }

        $total = $size * $size;
        $balance = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $balance * self::PENALTY_N4;
    }

    /**
     * Remember a run length, with the light border folded into the first run.
     *
     * @param  array<int, int>  $history
     */
    protected function addRun(int $length, array &$history, int $size): void
    {
        if ($history[0] === 0) {
            $length += $size;
        }

        array_unshift($history, $length);
        array_pop($history);
    }

    /**
     * Count the finder-like 1:1:3:1:1 patterns in the run history (0, 1 or 2).
     *
     * @param  array<int, int>  $history
     */
    protected function countFinderLike(array $history): int
    {
        $n = $history[1];
        $core = $n > 0
            && $history[2] === $n
            && $history[4] === $n
            && $history[5] === $n
            && $history[3] === $n * 3;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0)
            + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    /**
     * Close a row or column and count what the final run completed.
     *
     * @param  array<int, int>  $history
     */
    protected function terminateRun(bool $dark, int $length, array &$history, int $size): int
    {
        if ($dark) {
            $this->addRun($length, $history, $size);
            $length = 0;
        }

        $length += $size;
        $this->addRun($length, $history, $size);

        return $this->countFinderLike($history);
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
