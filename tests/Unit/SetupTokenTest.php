<?php

namespace Tests\Unit;

use App\Domain\Platform\Services\SetupToken;
use Tests\TestCase;

/**
 * Spec §49 / decision D20: only a SHA-256 hash at rest, single-use,
 * TTL-bound, per-IP attempt cap. No default password anywhere.
 */
class SetupTokenTest extends TestCase
{
    private SetupToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = new SetupToken;
        $this->token->consume(); // clean slate
    }

    protected function tearDown(): void
    {
        $this->token->consume();
        parent::tearDown();
    }

    public function test_only_the_hash_is_stored_on_disk(): void
    {
        $plain = $this->token->generate();

        $this->assertSame(64, strlen($plain));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);

        $contents = file_get_contents(storage_path('app/setup-token.sha256'));
        $this->assertStringNotContainsString($plain, $contents);

        $decoded = json_decode($contents, true);
        $this->assertSame(hash('sha256', $plain), $decoded['hash']);
        $this->assertArrayHasKey('expires_at', $decoded);

        $this->assertTrue($this->token->verify($plain));
        $this->assertFalse($this->token->verify(str_repeat('0', 64)));
    }

    public function test_token_expires_after_the_configured_ttl(): void
    {
        $plain = $this->token->generate();

        $this->travel(59)->minutes();
        $this->assertTrue($this->token->verify($plain), 'Token must survive inside its TTL window.');

        $this->travel(2)->minutes(); // 61 minutes in total > default 60
        $this->assertFalse($this->token->verify($plain), 'Token must fail closed after its TTL.');
    }

    public function test_consume_makes_the_token_single_use(): void
    {
        $plain = $this->token->generate();
        $this->assertTrue($this->token->exists());

        $this->token->consume();

        $this->assertFalse($this->token->exists());
        $this->assertFalse($this->token->verify($plain));
    }

    public function test_attempts_are_capped_per_ip(): void
    {
        $this->token->generate();

        $this->assertFalse($this->token->blocked('203.0.113.10'));

        for ($i = 0; $i < 5; $i++) {
            $this->token->recordAttempt('203.0.113.10');
        }

        $this->assertTrue($this->token->blocked('203.0.113.10'));
        $this->assertFalse($this->token->blocked('198.51.100.7'), 'The cap must be per-IP only.');
    }
}
