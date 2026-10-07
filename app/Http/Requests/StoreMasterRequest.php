<?php

namespace App\Http\Requests;

use App\Domain\Masters\Support\MasterCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generic master-data validation driven by MasterCatalog field metadata
 * (traceability §14). Code fields are normalised to upper-kebab and
 * unique per company (or global for geo masters).
 */
class StoreMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $entry = MasterCatalog::get((string) $this->route('type'));

        if ($entry === null) {
            return;
        }

        foreach ($entry['fields'] as $field => $meta) {
            if (($meta['type'] ?? 'text') !== 'text' || ! ($meta['upper'] ?? false)) {
                continue;
            }

            if (! $this->has($field)) {
                continue;
            }

            $value = strtoupper(str_replace(' ', '-', trim((string) $this->input($field))));
            $this->merge([$field => $value]);
        }
    }

    public function rules(): array
    {
        $entry = MasterCatalog::get((string) $this->route('type'));

        if ($entry === null) {
            abort(404, 'Unknown master data resource.');
        }

        $model = $entry['model'];
        $instance = $this->route('record');
        $rules = [];

        foreach ($entry['fields'] as $field => $meta) {
            $type = $meta['type'] ?? 'text';
            $fieldRules = [];

            if (($meta['required'] ?? false) && $type !== 'boolean') {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            switch ($type) {
                case 'boolean':
                    $fieldRules = ['sometimes', 'boolean'];
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    break;
                case 'date':
                    $fieldRules[] = 'date';
                    break;
                case 'select':
                    // A select whose options live in the database (a parent row,
                    // say) cannot be pinned to a static list: the allowed ids
                    // depend on the company and, on edit, on the record itself.
                    // Bounded here as an integer; the controller owns the rest.
                    $fieldRules[] = isset($meta['source'])
                        ? 'integer'
                        : Rule::in($meta['options'] ?? []);
                    break;
                default:
                    $fieldRules[] = 'string';
                    if (isset($meta['max'])) {
                        $fieldRules[] = 'max:'.(int) $meta['max'];
                    }
                    if ($meta['upper'] ?? false) {
                        $fieldRules[] = 'regex:/^[A-Z0-9][A-Z0-9_-]*$/';
                        $unique = Rule::unique((new $model)->getTable(), $field);
                        if ($instance !== null) {
                            $unique->ignore($instance instanceof Model ? $instance->getKey() : $instance);
                        }
                        if ($entry['company_scoped'] ?? true) {
                            $companyId = $this->user()?->company_id;
                            if ($companyId !== null) {
                                $unique->where('company_id', $companyId);
                            }
                        }
                        $fieldRules[] = $unique;
                    }
                    break;
            }

            $rules[$field] = $fieldRules;
        }

        if ($entry['company_scoped'] ?? true) {
            $rules['company_id'] = ['sometimes', 'nullable', 'integer'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $entry = MasterCatalog::get((string) $this->route('type'));
        $attributes = [];

        foreach ($entry['fields'] ?? [] as $field => $meta) {
            $attributes[$field] = strtolower((string) ($meta['label'] ?? $field));
        }

        return $attributes;
    }
}
