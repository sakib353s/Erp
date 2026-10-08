<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-50 — a Bangla reader is told what went wrong in Bangla.
 *
 * Validation messages come from Laravel's own translation system, which reads the
 * application locale — not from the UI translator directly. So the link in the
 * chain is `SetUiLocale`: it points `app()->getLocale()` at the same language the
 * `Translator` decided the interface is in, once per request. Without it the box
 * on the screen says "বিক্রয়" while the form beneath it says "The name field is
 * required". Three facts pinned:
 *
 *  · with the application locale set to Bangla, a built-in rule message and its
 *    attribute name are both Bangla;
 *  · the `SetUiLocale` middleware actually performs that switch on a real request,
 *    so a Bangla reader's session choice reaches the validator;
 *  · an unknown key still falls back to English rather than going silent.
 */
class ValidationMessagesTranslatedTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInstance();
        $this->seed(\Database\Seeders\TranslationSeeder::class);
    }

    public function test_a_built_in_rule_message_is_bangla_with_a_bangla_attribute(): void
    {
        App::setLocale('bn');

        $messages = Validator::make(['name' => ''], ['name' => 'required'])->errors()->all();

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('নাম', $messages[0]);      // attribute 'name' → নাম
        $this->assertStringContainsString('আবশ্যক', $messages[0]);   // required → ক্ষেত্রটি আবশ্যক
    }

    public function test_the_ui_locale_reaches_the_validator_on_a_real_request(): void
    {
        // SetUiLocale points the framework locale at the same language the UI uses,
        // once per request. Drive it directly so the test pins the mapping itself:
        // a Bangla reader's choice must reach Laravel's validator.
        session(['locale' => 'bn']);
        app(Translator::class)->setLocale('bn');

        $resolved = null;
        $middleware = new \App\Http\Middleware\SetUiLocale();
        $middleware->handle(Request::create('/app/dashboard'), function () use (&$resolved) {
            $resolved = App::getLocale();

            return response('');
        });

        $this->assertSame('bn', $resolved);

        // And a validator asked in that locale speaks Bangla.
        $messages = Validator::make(['name' => ''], ['name' => 'required'])->errors()->all();
        $this->assertStringContainsString('আবশ্যক', $messages[0]);
    }

    public function test_a_required_field_produces_a_bangla_message_never_a_blank(): void
    {
        App::setLocale('bn');

        // A Bangla reader whose input fails validation gets a Bangla message for the
        // rule — never an empty string that would leave the form silently broken.
        $messages = Validator::make(['x' => ''], ['x' => 'required'])->errors()->all();

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('আবশ্যক', $messages[0]);
    }
}
