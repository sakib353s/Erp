<?php

namespace App\Http\Requests;

use App\Domain\Settings\Support\GroupRules;
use Illuminate\Foundation\Http\FormRequest;

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

        foreach (GroupRules::fields(['fields' => (array) $this->fields]) as $key => $fieldRules) {
            $rules["settings.{$key}"] = $fieldRules;
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['settings' => 'settings']
            + GroupRules::attributes(['fields' => (array) $this->fields], 'settings.');
    }
}
