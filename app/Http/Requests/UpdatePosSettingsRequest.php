<?php

namespace App\Http\Requests;

/**
 * 02-47 POS settings — same strict metadata-driven validation as the
 * generic UpdateSettingsRequest, but bound to the fixed `pos` group so
 * the literal /app/settings/pos route needs no {group} parameter.
 */
class UpdatePosSettingsRequest extends UpdateSettingsRequest
{
    protected function prepareForValidation(): void
    {
        $definition = config('erp.settings.groups.pos');

        abort_unless(is_array($definition) && isset($definition['fields']), 404, 'Unknown settings group.');

        $this->fields = $definition['fields'];
    }
}
