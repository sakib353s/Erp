<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\MaintenanceRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * §15-31 — the error log viewer.
 *
 * Three things make this real rather than a file dump:
 *
 *  · **It reads only what it is allowed to read.** A file must sit directly in
 *    `storage/logs`, end in `.log`, and resolve — after symlinks — to a path
 *    inside that directory. A request naming `../../.env` gets nothing.
 *  · **It reads entries, not lines.** A Laravel entry is one header line plus its
 *    trace; the reader stitches them back together, so a stack frame is never
 *    mistaken for an event and the level filter works on the level.
 *  · **It never shows a secret.** Every entry passes through {@see LogRedactor}
 *    first, and the viewer says how many replacements it made rather than
 *    pretending the text is pristine.
 *
 * Reading backwards from the end is what makes a 400 MB log file usable: the
 * newest entries are the ones somebody is looking for.
 */
class LogReader
{
    /** Files larger than this are tailed from the end rather than read whole. */
    public const TAIL_BYTES = 2_000_000;

    /** The levels Laravel writes, ordered by how much they want attention. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** @return array<int, array{name: string, bytes: int, modified: ?string, modified_human: ?string, readable: bool}> */
    public function files(): array
    {
        $directory = $this->directory();

        if ($directory === null) {
            return [];
        }

        $files = [];

        foreach (File::files($directory) as $file) {
            if (strtolower($file->getExtension()) !== 'log') {
                continue;
            }

            $files[] = [
                'name' => $file->getFilename(),
                'bytes' => $file->getSize(),
                'modified' => Carbon::createFromTimestamp($file->getMTime())->toDateTimeString(),
                'modified_human' => Carbon::createFromTimestamp($file->getMTime())->diffForHumans(),
                'readable' => is_readable($file->getPathname()),
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp((string) $b['modified'], (string) $a['modified']));

        // Newest first, with the conventional log name leading when the timestamps
        // tie: `laravel.log` is where an unconfigured installation writes.
        usort($files, fn (array $a, array $b): int => $a['name'] === 'laravel.log' ? -1 : ($b['name'] === 'laravel.log' ? 1 : 0));

        return $files;
    }

    /**
     * Newest entries first, filtered by level and a plain search over the message.
     * The search runs *after* redaction, so it cannot be used to confirm a secret
     * that the viewer refuses to display.
     *
     * @return array{file: string, entries: array<int, array{level: string, at: ?string, message: string, trace: ?string, masked: int, line: int}>, scanned: int, truncated: bool, problems: array<int, string>}
     */
    public function entries(?string $file = null, int $limit = 100, ?string $level = null, ?string $search = null): array
    {
        $path = $this->resolve($file ?? 'laravel.log');
        $problems = [];

        if ($path === null) {
            return [
                'file' => $file ?? 'laravel.log',
                'entries' => [],
                'scanned' => 0,
                'truncated' => false,
                'problems' => ['No readable log file by that name. Log files live in storage/logs and end in .log.'],
            ];
        }

        $truncated = filesize($path) > self::TAIL_BYTES;
        $text = $this->tail($path);

        $entries = [];
        $scanned = 0;

        foreach ($this->split($text) as $index => $entry) {
            $scanned++;

            if ($level !== null && $level !== '' && strtolower($entry['level']) !== strtolower($level)) {
                continue;
            }

            $redacted = LogRedactor::redact($entry['message'].($entry['trace'] ?? ''));

            if ($search !== null && $search !== '' && stripos($redacted, $search) === false) {
                continue;
            }

            $entries[] = [
                'level' => $entry['level'],
                'at' => $entry['at'],
                'message' => LogRedactor::redact($entry['message']),
                'trace' => $entry['trace'] === null ? null : LogRedactor::redact($entry['trace']),
                'masked' => LogRedactor::maskedCount($entry['message'].($entry['trace'] ?? '')),
                'line' => $index + 1,
            ];
        }

        if ($truncated) {
            $problems[] = 'The file is larger than '.MaintenanceRun::humanBytes(self::TAIL_BYTES).': only the newest part of it was read.';
        }

        $total = count($entries);

        // Newest first: an operator opening a log viewer is looking at what just
        // happened, not at the beginning of the year.
        $entries = array_slice(array_reverse($entries), 0, max(1, min($limit, 500)));

        if ($total > count($entries)) {
            $problems[] = sprintf('%d matching entr(ies) — showing the newest %d. Narrow the filter to see the older ones.', $total, count($entries));
        }

        return [
            'file' => basename($path),
            'entries' => $entries,
            'scanned' => $scanned,
            'truncated' => $truncated,
            'problems' => $problems,
        ];
    }

    /** Counts per level in the newest part of a file, for the viewer's header. */
    public function summary(?string $file = null): array
    {
        $path = $this->resolve($file ?? 'laravel.log');

        if ($path === null) {
            return ['levels' => [], 'total' => 0];
        }

        $levels = [];

        foreach ($this->split($this->tail($path)) as $entry) {
            $levels[$entry['level']] = ($levels[$entry['level']] ?? 0) + 1;
        }

        return ['levels' => $levels, 'total' => array_sum($levels)];
    }

    /* -------------------------------------------------------------- internals */

    protected function directory(): ?string
    {
        $path = storage_path('logs');

        return is_dir($path) && is_readable($path) ? $path : null;
    }

    /** Resolve a requested file name, or null when it is not one we may read. */
    protected function resolve(string $name): ?string
    {
        $directory = $this->directory();

        if ($directory === null) {
            return null;
        }

        // A log file is a file name, not a path: `../.env` is not a name.
        if (basename($name) !== $name || ! str_ends_with(strtolower($name), '.log')) {
            return null;
        }

        $candidate = $directory.DIRECTORY_SEPARATOR.$name;

        if (! is_file($candidate) || ! is_readable($candidate)) {
            return null;
        }

        $real = realpath($candidate);

        if ($real === false || dirname($real) !== $directory) {
            return null; // a symlink pointing somewhere else is not a log file
        }

        return $real;
    }

    /** The newest part of the file, starting at a line boundary. */
    protected function tail(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = filesize($path) ?: 0;

            if ($size > self::TAIL_BYTES) {
                fseek($handle, $size - self::TAIL_BYTES);
                fgets($handle); // discard the partial line
            }

            $text = stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }

        return $text;
    }

    /**
     * Split log text into entries. Laravel's format is:
     *
     *   [2026-10-08 09:14:00] production.ERROR: Something broke {"ctx":"here"}
     *   #0 /path/file.php(12): ...
     *
     * A line that does not start with `[` continues the previous entry.
     *
     * @return array<int, array{level: string, at: ?string, message: string, trace: ?string}>
     */
    protected function split(string $text): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:[^\]]*)\]\s+([^.\]]*)\.([A-Z]+):\s?(.*)$/', $line, $match) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'at' => $match[1],
                    'level' => strtolower($match[3]),
                    'message' => trim($match[4]),
                    'trace' => null,
                ];

                continue;
            }

            if ($current === null) {
                continue; // leading noise (a BOM, an old pre-Laravel line)
            }

            if ($line === '') {
                continue;
            }

            $current['trace'] = ($current['trace'] === null ? '' : $current['trace']."\n").$line;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }
}
