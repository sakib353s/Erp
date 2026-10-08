<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs with sleeping faked.
     *
     * Laravel's constant-time password check wraps a sign-in attempt in a
     * Timebox that pads the attempt to a flat 200 ms, so that "no such user"
     * and "wrong password" take the same time to answer. That padding is a
     * real defence and it stays in production; in a suite that signs in
     * hundreds of times it is dead wall-clock time, and here it is worse than
     * slow — the wasm PHP runtime takes the padded sleep down mid-request.
     *
     * Faking the call (Laravel's own test-suite idiom) keeps the guard's
     * behaviour identical while making the padding observable:
     * `Sleep::assertSlept()` can prove a failure was padded without waiting
     * for it. `AuthenticationTest` does exactly that.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }
}
