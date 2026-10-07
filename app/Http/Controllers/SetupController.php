<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Services\SetupService;
use App\Http\Requests\SetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * First-boot installer (spec §49). Reachable only while the instance
 * is unconfigured; POST runs SetupService (token + password policy +
 * singleton guard) and signs the new super admin in.
 */
class SetupController extends Controller
{
    public function __construct(protected SetupService $setup) {}

    public function show(): View
    {
        return view('setup.show', [
            'tokenRequired' => ! $this->setup->tokenExists(),
        ]);
    }

    public function store(SetupRequest $request): RedirectResponse
    {
        $admin = $this->setup->run($request->validated(), $request->ip());

        auth()->login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('status', 'Instance initialised. Welcome — finish the onboarding checklist to complete your setup.');
    }
}
