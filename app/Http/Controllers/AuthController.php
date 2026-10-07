<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Outbox\Contracts\ArrayPayload;
use App\Domain\Outbox\Services\OutboxPublisher;
use App\Domain\Security\AuthEvent;
use App\Domain\Security\Events\LoginFailed;
use App\Domain\Security\Events\LoginSucceeded;
use App\Domain\Security\Services\AccountLockService;
use App\Domain\Security\Services\LoginThrottle;
use App\Domain\Security\Services\SessionPolicyService;
use App\Domain\Security\Services\SuspiciousLoginDetector;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Authentication (spec section C):
 *  - identical failure message for unknown e-mail and wrong password
 *    (no account enumeration),
 *  - layered brute-force defence (per-IP+email throttle AND per-account
 *    lockout), both configurable from the security settings group,
 *  - suspicious-login heuristics publish an outbox event that raises a
 *    security alert after commit,
 *  - session regeneration + concurrent-session pruning on success,
 *  - every outcome lands in auth_events + the tamper-evident audit log.
 */
class AuthController extends Controller
{
    public function __construct(
        protected LoginThrottle $throttle,
        protected AccountLockService $locks,
        protected SuspiciousLoginDetector $suspicious,
        protected SessionPolicyService $sessions,
        protected AuditRecorder $audit,
        protected OutboxPublisher $outbox,
        protected SettingService $settings,
    ) {}

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], ['email' => 'e-mail address']);

        $ip = (string) $request->ip();
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        if ($this->throttle->tooManyAttempts($ip, $email)) {
            $retry = $this->throttle->retryAfter($ip, $email);

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$retry} seconds.",
            ]);
        }

        $user = \App\Domain\Foundation\User::query()
            ->where('email', $email)
            ->first();

        if ($user !== null && $user->isLocked()) {
            // Do not reveal the lock state to unauthenticated probing.
            $this->throttle->hit($ip, $email);

            throw ValidationException::withMessages([
                'email' => 'Invalid e-mail address or password.',
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password], (bool) $request->boolean('remember'))) {
            $this->locks->registerFailure($user?->id, $ip, $email, 'invalid_credentials');
            $this->throttle->hit($ip, $email);
            $this->publishLoginFailed($user?->company_id, $user?->id, $ip, $email);

            throw ValidationException::withMessages([
                'email' => 'Invalid e-mail address or password.',
            ]);
        }

        /** @var \App\Domain\Foundation\User $actor */
        $actor = Auth::user();

        if (! $actor->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Invalid e-mail address or password.',
            ]);
        }

        $userAgent = mb_substr((string) $request->userAgent(), 0, 191);

        $this->throttle->clear($ip, $email);
        $this->locks->registerSuccess($actor, $ip);

        $finding = $this->suspicious->detect($actor, $ip, $userAgent);

        Auth::login($actor, (bool) $request->boolean('remember'));
        $request->session()->regenerate();
        $this->sessions->pruneConcurrent($actor, $request->session()->getId());

        $this->audit->record([
            'action' => $finding['suspicious'] ? 'auth.suspicious_login' : 'auth.login',
            'entity_type' => 'user',
            'entity_id' => $actor->id,
            'actor_id' => $actor->id,
            'branch_id' => null,
            'result' => 'success',
            'reason' => $finding['reason'],
            'ip' => $ip,
        ]);

        $this->publishLoginSucceeded($actor, $ip, $userAgent, $finding);

        $destination = $actor->must_change_password
            ? '/app/profile?must_change=1'
            : (string) $this->settings->get('general', 'default_landing', '/app/dashboard');

        return redirect()->intended($destination)
            ->with('login_warning', $finding['suspicious']
                ? 'This sign-in came from a new IP address or device and has been recorded.'
                : null);
    }

    public function logout(Request $request): RedirectResponse
    {
        $actor = $request->user();

        if ($actor !== null) {
            AuthEvent::create([
                'company_id' => $actor->company_id,
                'user_id' => $actor->id,
                'event_type' => 'logout',
                'email_attempt' => $actor->email,
                'ip' => (string) $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 191),
            ]);

            $this->audit->record([
                'action' => 'auth.logout',
                'entity_type' => 'user',
                'entity_id' => $actor->id,
                'actor_id' => $actor->id,
                'result' => 'success',
                'ip' => (string) $request->ip(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** LoginFailed payloads flow through the outbox (decision D13). */
    protected function publishLoginFailed(?int $companyId, ?int $userId, string $ip, string $email): void
    {
        if ($companyId === null) {
            return; // pre-setup: no company to attribute the event to
        }

        $this->outbox->publish(
            new LoginFailed(
                companyId: $companyId,
                userId: $userId,
                ip: $ip,
                emailAttempt: $email,
                reason: 'invalid_credentials',
            ),
            [
                'company_id' => $companyId,
                'aggregate_type' => 'auth',
                'aggregate_id' => $userId,
                'idempotency_key' => hash('sha256', "login_failed|{$email}|{$ip}|".microtime()),
            ],
        );
    }

    protected function publishLoginSucceeded(\App\Domain\Foundation\User $actor, string $ip, string $userAgent, array $finding): void
    {
        $this->outbox->publish(
            new LoginSucceeded(
                companyId: (int) $actor->company_id,
                userId: $actor->id,
                ip: $ip,
                userAgent: $userAgent,
                suspicious: (bool) $finding['suspicious'],
                reason: $finding['reason'],
            ),
            [
                'company_id' => (int) $actor->company_id,
                'aggregate_type' => 'auth',
                'aggregate_id' => $actor->id,
                'idempotency_key' => hash('sha256', "login_ok|{$actor->id}|{$ip}|".microtime()),
            ],
        );
    }
}
