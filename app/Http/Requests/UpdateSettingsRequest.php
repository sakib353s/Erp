<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Group settings validated STRICTLY from config('erp.settings.groups')
 * field metadata: unknown groups → 404, unknown keys are dropped by
 * validated(), types/min/max/options come from the group definition —
 * no hard-coded validation duplicated in controllers.
 */
class UpdateSettingsRequest extends FormRequest
{
    protected ?array $fields = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $group = (string) $this->route('group');
        $definition = config("erp.settings.groups.{$group}");

        abort_unless(is_array($definition) && isset($definition['fields']), 404, 'Unknown settings group.');

        $this->fields = $definition['fields'];
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach ((array) $this->fields as $key => $meta) {
            $type = $meta['type'] ?? 'text';
            $field = "settings.{$key}";
            $base = ['nullable'];

            $rules[$field] = match ($type) {
                'boolean' => ['nullable', 'boolean'],
                'number' => $this->withLimits($base, ['numeric'], $meta),
                'integer' => $this->withLimits($base, ['integer'], $meta),
                'select' => ['nullable', 'string', Rule::in(array_keys($meta['options'] ?? []))],
                default => ['nullable', 'string', 'max:'.(int) ($meta['max'] ?? 500)],
            };
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = ['settings' => 'settings'];

        foreach ((array) $this->fields as $key => $meta) {
            $attributes["settings.{$key}"] = $meta['label'] ?? $key;
        }

        return $attributes;
    }

    /** @return array<int, string> */
    protected function withLimits(array $rules, array $typeRules, array $meta): array
    {
        if (isset($meta['min'])) {
            $typeRules[] = 'min:'.$meta['min'];
        }

        if (isset($meta['max'])) {
            $typeRules[] = 'max:'.$meta['max'];
        }

        return array_merge($rules, $typeRules);
    }
}
