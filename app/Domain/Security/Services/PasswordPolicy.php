<?php

namespace App\Domain\Security\Services;

use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;

/**
 * Configurable password policy (spec section C) — resolved from the
 * `security` settings group at runtime, never hard-coded per screen.
 */
class PasswordPolicy
{
    public function __construct(protected SettingService $settings) {}

    /**
     * @return array<int, string> validation errors (empty = valid)
     */
    public function validate(string $password, ?User $user = null, ?string $email = null): array
    {
        $errors = [];

        $min = max(8, $this->settings->getInt('security', 'password_min_length', 10));
        $max = (int) config('erp.security.password.max_length', 200);

        if (mb_strlen($password) < $min) {
            $errors[] = "Password must be at least {$min} characters long.";
        }

        if (mb_strlen($password) > $max) {
            $errors[] = "Password must not exceed {$max} characters.";
        }

        if (config('erp.security.password.require_upper') && $this->settings->getBool('security', 'password_require_upper', true)
            && ! preg_match('/[A-Z]/u', $password)) {
            $errors[] = 'Password must contain an uppercase letter.';
        }

        if ($this->settings->getBool('security', 'password_require_lower', true)
            && ! preg_match('/[a-z]/u', $password)) {
            $errors[] = 'Password must contain a lowercase letter.';
        }

        if ($this->settings->getBool('security', 'password_require_digit', true)
            && ! preg_match('/[0-9]/u', $password)) {
            $errors[] = 'Password must contain a digit.';
        }

        if ($this->settings->getBool('security', 'password_require_special', true)
            && ! preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $errors[] = 'Password must contain a special character.';
        }

        if (in_array(strtolower($password), config('erp.security.password.blocked', []), true)) {
            $errors[] = 'Password is too common.';
        }

        if ($email !== null && str_contains(strtolower($password), strtolower(strtok($email, '@')))) {
            $errors[] = 'Password must not contain your email name.';
        }

        $errors = array_merge($errors, $this->historyErrors($password, $user));

        return array_values(array_unique($errors));
    }

    /**
     * Record the change: rotation history, timestamps, force-change flag.
     * The raw password is never stored — only the hasher output rotates
     * through the bounded history array.
     */
    public function markChanged(User $user): void
    {
        $depth = max(1, (int) config('erp.security.password.history_depth', 5));
        $history = $user->password_history ?? [];

        // Keep the CURRENT hash in history before it is replaced.
        if ($user->getRawOriginal('password') !== null) {
            array_unshift($history, $user->getRawOriginal('password'));
        }

        $user->password_history = array_slice($history, 0, $depth);
        $user->password_changed_at = now();
        $user->must_change_password = false;
        $user->save();
    }

    public function isExpired(User $user): bool
    {
        $maxAge = $this->settings->getInt('security', 'password_max_age_days', 90);

        if ($maxAge <= 0 || $user->password_changed_at === null) {
            return false;
        }

        return $user->password_changed_at->copy()->addDays($maxAge)->isPast();
    }

    protected function historyErrors(string $password, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $current = $user->getRawOriginal('password');

        if ($current !== null && \Illuminate\Support\Facades\Hash::check($password, $current)) {
            return ['Password was used before. Choose a new password.'];
        }

        foreach ($user->password_history ?? [] as $oldHash) {
            if (\Illuminate\Support\Facades\Hash::check($password, (string) $oldHash)) {
                return ['Password was used before. Choose a new password.'];
            }
        }

        return [];
    }
}
