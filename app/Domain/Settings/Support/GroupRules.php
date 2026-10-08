<?php

namespace App\Domain\Settings\Support;

use Illuminate\Validation\Rule;

/**
 * Validation rules for a settings group, derived from the group's own metadata
 * in `config/erp.php`.
 *
 * One place builds them, because two screens write groups: the company screen
 * (`settings[<key>]` at company scope) and the branch screen
 * (`settings[<group>][<key>]` for one branch). If each built its own rules, the
 * branch screen would quietly become the laxer door into the same table — and a
 * value that could not be typed into the company form would be storable per
 * branch, which is the same value with a different label on it.
 */
class GroupRules
{
    /**
     * Rules for one group's fields, keyed by the field name alone. The caller
     * prefixes: `settings.` for the company screen, `settings.<group>.` for the
     * branch screen.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, array<int, mixed>>
     */
    public static function fields(array $definition): array
    {
        $rules = [];

        foreach ((array) ($definition['fields'] ?? []) as $key => $meta) {
            $type = $meta['type'] ?? 'text';

            $rules[(string) $key] = match ($type) {
                'boolean' => ['nullable', 'boolean'],
                'number' => self::ranged(['nullable', 'numeric'], $meta),
                'integer' => self::ranged(['nullable', 'integer'], $meta),
                'select' => ['nullable', 'string', Rule::in(array_keys((array) ($meta['options'] ?? [])))],
                default => ['nullable', 'string', 'max:'.(int) ($meta['max'] ?? 500)],
            };
        }

        return $rules;
    }

    /** Attributes (human names) for the same fields, so errors read properly. */
    public static function attributes(array $definition, string $prefix): array
    {
        $attributes = [];

        foreach ((array) ($definition['fields'] ?? []) as $key => $meta) {
            $attributes[$prefix.$key] = $meta['label'] ?? (string) $key;
        }

        return $attributes;
    }

    /**
     * @param  array<int, mixed>  $rules
     * @param  array<string, mixed>  $meta
     * @return array<int, mixed>
     */
    protected static function ranged(array $rules, array $meta): array
    {
        if (isset($meta['min'])) {
            $rules[] = 'min:'.$meta['min'];
        }

        if (isset($meta['max'])) {
            $rules[] = 'max:'.$meta['max'];
        }

        return $rules;
    }
}
