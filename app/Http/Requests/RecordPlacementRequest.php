<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §04-44: where the goods went. A quantity without a bin is refused downstream,
 * because a putaway that does not say where the goods are teaches the map
 * nothing — and the map is the only reason bin tables exist.
 */
class RecordPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'placements' => ['array'],
            'placements.*.bin_id' => ['nullable', 'integer'],
            'placements.*.qty' => ['nullable', 'numeric', 'min:0'],
            'placements.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'placements.*.bin_id' => 'bin',
            'placements.*.qty' => 'placed quantity',
        ];
    }
}
