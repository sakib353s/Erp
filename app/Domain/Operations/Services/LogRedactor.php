<?php

namespace App\Domain\Operations\Services;

/**
 * §15-31 — the redactor that stands between the log file and the browser.
 *
 * A Laravel log is the most informative file in the installation and the one
 * most likely to contain a secret: a failed SMTP send logs the credentials it
 * tried, an API exception logs the bearer token, a validation error echoes the
 * body somebody posted. The viewer therefore never streams the file: every line
 * is masked first, and the mask is deliberately blunt — a value that looks like
 * a credential is replaced, not partially shown.
 *
 * What is *not* masked: dates, levels, class names, file paths, exception
 * messages, stack frames, and the shape of a payload. A log viewer whose
 * redaction removed the reason the error happened would be a viewer nobody uses,
 * and then the real log gets read with `cat` instead.
 */
class LogRedactor
{
    /** Patterns applied in order; the first match on a span wins. */
    private const RULES = [
        // key = value, key: value, "key": "value" — passwords, tokens, secrets,
        // API keys, mail credentials, and anything with a *_key / *_secret name.
        '/(?i)\b(password|passwd|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|client[_-]?secret|authorization|auth|credential|private[_-]?key|encryption[_-]?key|cvv|pin)\b(\s*[:=]\s*)(["\']?)([^\s,"\'&}]{3,})\3/'
            => '$1$2$3••••••$3',

        // Bearer tokens and Basic auth headers.
        '/(?i)\b(bearer|basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/' => '$1 ••••••',

        // Credentials inside a DSN: mysql://user:secret@host.
        '#([a-z][a-z0-9+.\-]*://[^:/\s@]+):([^@/\s]+)@#i' => '$1:••••••@',

        // Long opaque strings that are almost certainly a key: 32+ hex, 40+ base64.
        '/\b[A-Fa-f0-9]{32,}\b/' => '••••••',
        '/\b[A-Za-z0-9+\/_-]{40,}={0,2}\b/' => '••••••',

        // Card-like numbers: replaced whole. Keeping the last four would be
        // friendlier to read and still leaves a cardholder's number on screen —
        // an operator debugging a payment does not need it, so it goes.
        '/\b(?:\d[ -]?){12,18}\d\b/' => '••••••••••••',

        // Email addresses: the domain stays (it explains the failure — wrong
        // host, bad relay), the mailbox does not.
        '/\b([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/' => '$1•••$2',

        // Bangladeshi mobile numbers.
        '/\b(?:\+?880|0)1[3-9]\d{8}\b/' => '•••••••••••',
    ];

    /** Does this line look like it holds something worth masking? */
    public static function looksSensitive(string $line): bool
    {
        return self::redact($line) !== $line;
    }

    /** Mask a full line, an entry body, or an exception trace. */
    public static function redact(string $text): string
    {
        foreach (self::RULES as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * A line's risk, for the viewer's honest note: how many distinct things were
     * masked. Shown as a count on the entry rather than as a silent edit.
     */
    public static function maskedCount(string $original): int
    {
        return substr_count(self::redact($original), '••••');
    }
}
