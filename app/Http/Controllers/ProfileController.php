<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Security\Services\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-service profile: name/phone, password change (real policy +
 * history + audit + must_change_password clearing). A user can always
 * manage their own record even without users.update (UserPolicy).
 */
class ProfileController extends Controller
{
    public function __construct(
        protected PasswordPolicy $passwords,
        protected AuditRecorder $audit,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user()->load(['roles', 'branchAssignments', 'defaultBranch']);

        return view('profile.show', [
            'user' => $user,
            'mustChange' => (bool) $request->query('must_change')
                || $user->must_change_password,
            'passwordExpired' => $this->passwords->isExpired($user),
            'pendingPasswordErrors' => [],
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'phone']));
        $user->save();

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $user->id,
            'before' => ['name' => $user->getOriginal('name')],
            'after' => ['name' => $user->name, 'phone' => $user->phone],
            'ip' => (string) $request->ip(),
        ]);

        return back()->with('status', 'Profile updated.');
    }

    public function changePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $errors = $this->passwords->validate(
            (string) $request->validated('password'),
            $user,
            $user->email,
        );

        if ($errors !== []) {
            return back()->withErrors(['password' => $errors]);
        }

        $user->password = (string) $request->validated('password');
        $user->must_change_password = false;
        $user->save();

        $this->passwords->markChanged($user);

        $this->audit->record([
            'action' => 'auth.password_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $user->id,
            'result' => 'success',
            'ip' => (string) $request->ip(),
        ]);

        $request->session()->regenerate();

        return redirect()->route('profile.show')->with('status', 'Password changed successfully.');
    }
}
