<?php

namespace App\Domain\Audit\Services;

/**
 * Removes secrets from audit payloads (Rule 16 + spec section C:
 * never place secrets into audit logs).
 */
class AuditRedactor
{
    public static function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return self::scalar($value);
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (self::isSensitive((string) $key)) {
                $out[$key] = '[REDACTED]';

                continue;
            }

            $out[$key] = is_array($item) ? self::redact($item) : self::scalar($item);
        }

        return $out;
    }

    protected static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (config('erp.security.redaction_keys', []) as $needle) {
            $needle = strtolower((string) $needle);

            if ($key === $needle || str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function scalar(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > 2000) {
            return mb_substr($value, 0, 2000).'…[truncated]';
        }

        if (is_resource($value) || $value instanceof \Closure) {
            return '[unserializable]';
        }

        return $value;
    }
}
