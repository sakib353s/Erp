<?php

namespace Tests\Feature;

use App\Domain\Security\AuthEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Spec §C: generic sign-in errors, throttling, lockout, and truthful
 * security-event recording.
 */
class AuthenticationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    public function test_wrong_password_and_unknown_email_produce_the_identical_generic_error(): void
    {
        $this->bootInstance();

        $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => 'WrongPass9!xx'])
            ->assertSessionHasErrors(['email' => 'Invalid e-mail address or password.']);
        $this->assertGuest();

        $this->post('/login', ['email' => 'nobody@instance.test', 'password' => 'WrongPass9!xx'])
            ->assertSessionHasErrors(['email' => 'Invalid e-mail address or password.']);
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_sign_in(): void
    {
        $admin = $this->bootInstance();
        $admin->forceFill(['status' => 'disabled'])->save();

        $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])
            ->assertSessionHasErrors(['email' => 'Invalid e-mail address or password.']);
        $this->assertGuest();
    }

    public function test_valid_credentials_authenticate_and_record_the_login(): void
    {
        $admin = $this->bootInstance();

        $response = $this->post('/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);

        $response->assertStatus(302);
        $this->assertAuthenticated();

        $admin->refresh();
        $this->assertNotNull($admin->last_login_at);
        $this->assertNull($admin->locked_until);
        $this->assertSame(0, (int) $admin->failed_login_count);
    }

    public function test_five_failures_lock_the_account_and_even_the_correct_password_is_refused(): void
    {
        $admin = $this->bootInstance();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => 'Nope!Pass123'])
                ->assertSessionHasErrors('email');
        }

        $admin->refresh();
        $this->assertSame(5, (int) $admin->failed_login_count);
        $this->assertNotNull($admin->locked_until);
        $this->assertTrue($admin->locked_until->isFuture());

        // Truthful security events for every failure.
        $this->assertSame(5, AuthEvent::query()->where('event_type', 'login_failed')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.lockout']);

        // Locked: the CORRECT password still yields the generic error.
        $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])
            ->assertSessionHasErrors(['email' => 'Invalid e-mail address or password.']);
        $this->assertGuest();
    }

    public function test_sign_in_attempts_are_throttled_per_identity(): void
    {
        $this->bootInstance();

        // Unknown identity: hammer past the 20 attempts / 60s cap.
        for ($i = 0; $i < 21; $i++) {
            $response = $this->post('/login', [
                'email' => 'bruteforce@instance.test',
                'password' => 'Guess!Pass123',
            ]);
        }

        $msg = $this->flashedEmailError();
        $this->assertStringStartsWith('Too many sign-in attempts. Try again in', $msg);
        $this->assertGuest();
    }

    /** The flashed 'email' error, tolerant of ViewErrorBag or the normalized array shape. */
    private function flashedEmailError(): string
    {
        $errors = session('errors');

        if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
            return (string) $errors->first('email');
        }

        if (is_array($errors)) {
            return (string) (data_get($errors, 'default.messages.email.0')
                ?? data_get($errors, 'email.0')
                ?? '');
        }

        return '';
    }
}
