<?php

namespace App\Http\Controllers;

use App\Domain\Settings\Services\SettingService;
use App\Http\Requests\UpdatePosSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 02-47 POS Settings — the engine-driven `pos` group on dedicated
 * routes behind `pos.settings.configure`, registered before the generic
 * /app/settings/{group} catch-all so the generic settings.view/update
 * keys never open this screen. Rendering and persistence reuse the
 * settings engine (setting_history + `config.update` audit).
 */
class PosSettingsController extends Controller
{
    public function __construct(
        protected SettingService $settings,
    ) {}

    public function show(Request $request): View
    {
        $definition = $this->definition();

        return view('settings.show', [
            'group' => 'pos',
            'definition' => $definition,
            'values' => $this->settings->group('pos'),
            'updated_at' => null,
        ]);
    }

    public function update(UpdatePosSettingsRequest $request): RedirectResponse
    {
        $this->definition();

        $result = $this->settings->setGroup(
            'pos',
            $request->validated('settings'),
            null,
            $request->user(),
        );

        $changed = array_keys(array_filter($result, fn ($rejected) => ! $rejected));

        return back()->with(
            'status',
            $changed === []
                ? 'No changes to save.'
                : 'Saved '.count($changed).'.',
        );
    }

    /** @return array<string, array<string, mixed>> */
    protected function definition(): array
    {
        $definition = config('erp.settings.groups.pos');

        abort_unless(is_array($definition) && isset($definition['fields']), 404, 'Unknown settings group.');

        return $definition;
    }
}
