<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Documents\Services\DocumentShareService;
use App\Domain\Documents\Services\FileUploadService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Sales\PublicAccessLog;
use Carbon\Carbon;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-21 / §16-22 — the public document link and the secure download.
 *
 * The rules this file exists to pin down:
 *
 *  · **the database never holds the capability** — publishing stores the
 *    SHA-256 of the address, so a read-only leak of the `documents` table
 *    publishes nothing while the screen can still show the address again;
 *  · **rotation and withdrawal are real** — the old address 404s the moment the
 *    rotation moves, a withdrawal keeps the date it happened, and an expiry
 *    closes the link on its own;
 *  · **the download is the stored bytes, named safely** — streamed from the
 *    private disk, never a path from the request, and never a directory;
 *  · **every visit and every download is written down** — a view and a download
 *    are different facts and the log keeps them apart;
 *  · **a file that is not stored cannot be published**, because a link to a
 *    missing file is a promise the application cannot keep.
 */
class DocumentShareTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        Storage::fake('local');
    }

    /* ------------------------------------------------------------------ helpers */

    protected function share(): DocumentShareService
    {
        return app(DocumentShareService::class);
    }

    protected function document(string $name = 'March statement.pdf', string $bytes = '%PDF-1.4 stored bytes for the test'): Document
    {
        return app(FileUploadService::class)->store(
            UploadedFile::fake()->createWithContent($name, $bytes),
            $this->admin,
            ['purpose' => 'attachment'],
        );
    }

    /** @param array<int, string> $keys */
    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();
        $this->grant($user, $keys);

        return $user->fresh();
    }

    /* ------------------------------------------------------------ publishing */

    public function test_a_document_that_is_not_published_has_no_address(): void
    {
        $document = $this->document();

        $this->assertNull($this->share()->url($document));

        $this->actingAs($this->admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeText('This file is not shared.')
            ->assertDontSeeText('Open the page');

        $this->get('/public/d/'.str_repeat('b', 43))->assertNotFound();
    }

    public function test_publishing_stores_only_the_digest_of_the_address(): void
    {
        $document = $this->document();

        $token = $this->share()->publish($document, $this->admin);
        $document->refresh();

        $this->assertSame(43, strlen($token));
        $this->assertSame(1, (int) $document->public_token_version);
        $this->assertSame(hash('sha256', $token), $document->public_token_hash);
        $this->assertNotNull($document->public_token_issued_at);
        $this->assertNull($document->public_token_expires_at, 'A permanent link is one with no expiry.');

        $row = (array) DB::table('documents')->where('id', $document->id)->first();
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "The address was stored in {$column}.");
        }

        $this->assertDatabaseHas('audit_events', [
            'action' => 'documents.public_link_issued',
            'entity_id' => $document->id,
        ]);

        // Publishing twice hands back the same address rather than a new one:
        // the file already has a link.
        $this->assertSame($token, $this->share()->publish($document->fresh(), $this->admin));
    }

    public function test_the_public_page_serves_the_metadata_and_nothing_else(): void
    {
        $document = $this->document();
        $token = $this->share()->publish($document, $this->admin);

        $response = $this->get(route('public.document.show', $token))->assertOk();

        $response->assertSeeText('March statement.pdf');
        $response->assertSeeText($this->admin->company->name);
        $response->assertSeeText('application/pdf');
        $response->assertSeeText('This page serves one file');

        // No internal path, no other document, no warehouse of ids.
        $response->assertDontSeeText((string) $document->path);
        $response->assertDontSeeText('app/documents');
        $response->assertSeeText('Download March statement.pdf');
    }

    public function test_the_download_streams_the_stored_bytes_and_is_recorded_separately(): void
    {
        $document = $this->document(bytes: '%PDF-1.4 the actual bytes');
        $token = $this->share()->publish($document, $this->admin);

        $this->get(route('public.document.show', $token))->assertOk();

        $response = $this->get(route('public.document.download', $token))->assertOk();
        $this->assertSame('%PDF-1.4 the actual bytes', $response->streamedContent());

        // One log row for the view and one for the download, both carrying the
        // token's hash rather than the token.
        $logs = PublicAccessLog::query()->where('subject_type', 'document')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(hash('sha256', $token), $logs->first()->access_token);

        $this->assertSame(1, AuditEvent::query()->where('action', 'documents.public_file_downloaded')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'documents.public_link_viewed')->count());

        // And the file's own screen reads those two figures back.
        $this->actingAs($this->admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeText('Opens')
            ->assertSeeText('Downloads');
    }

    public function test_the_download_name_cannot_climb_out_of_a_directory(): void
    {
        // The upload pipeline already stores a basename, so the sanitiser is
        // defence in depth for a name that arrives from somewhere else (an
        // import, an older row, a hand-edited database).
        $document = $this->document();
        $document->forceFill(['original_name' => '../../etc/passwd.pdf'])->save();
        $document->refresh();

        $token = $this->share()->publish($document, $this->admin);

        $this->assertSame('..-..-etc-passwd.pdf', $this->share()->safeName($document));

        $response = $this->get(route('public.document.download', $token))->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString('passwd.pdf', $disposition);
        $this->assertStringNotContainsString('../', $disposition);
    }

    /* ------------------------------------------------------- life cycle */

    public function test_a_rotated_address_stops_working(): void
    {
        $document = $this->document();
        $first = $this->share()->publish($document, $this->admin);
        $second = $this->share()->rotate($document->fresh(), $this->admin);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, (int) $document->fresh()->public_token_version);

        $this->get(route('public.document.show', $first))->assertNotFound();
        $this->get(route('public.document.download', $first))->assertNotFound();
        $this->get(route('public.document.show', $second))->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'documents.public_link_rotated',
            'entity_id' => $document->id,
        ]);
    }

    public function test_a_withdrawn_address_stops_working_and_the_screen_says_so(): void
    {
        $document = $this->document();
        $token = $this->share()->publish($document, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('documents.share.revoke', $document))
            ->assertRedirect()
            ->assertSessionHas('status');

        $document->refresh();

        $this->assertNull($document->public_token_hash);
        $this->assertSame(0, (int) $document->public_token_version);
        $this->assertNotNull($document->public_token_revoked_at);
        $this->assertFalse($this->share()->published($document));

        $this->get(route('public.document.show', $token))->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeText('Withdrawn '.$document->public_token_revoked_at->format('d M Y, H:i'));

        // Withdrawing a second time says there was nothing live, rather than
        // pretending it withdrew something.
        $this->actingAs($this->admin)
            ->post(route('documents.share.revoke', $document))
            ->assertRedirect()
            ->assertSessionHas('status', 'There was no live link for '.$document->original_name.'.');
    }

    public function test_a_link_with_an_expiry_closes_on_its_own(): void
    {
        $document = $this->document();

        $token = $this->share()->publish($document, $this->admin, now()->addDays(2));

        $this->get(route('public.document.show', $token))->assertOk();

        Carbon::setTestNow(Carbon::parse('2027-03-13 09:00:00'));

        $document->refresh();

        $this->assertNotNull($document->public_token_expires_at);
        $this->assertFalse($this->share()->published($document));
        $this->assertNull($this->share()->url($document));

        $this->get(route('public.document.show', $token))->assertNotFound();
        $this->get(route('public.document.download', $token))->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeText('The link expired on 12 Mar 2027');
    }

    public function test_the_expiry_days_are_bounded_when_publishing_from_the_screen(): void
    {
        $document = $this->document();

        $this->actingAs($this->admin)
            ->post(route('documents.share.publish', $document), ['expires_in_days' => 4000])
            ->assertSessionHasErrors('expires_in_days');

        $this->assertNull($document->fresh()->public_token_hash);
    }

    public function test_a_missing_file_cannot_be_published(): void
    {
        $document = $this->document();

        Storage::disk('local')->delete($document->path);

        $this->assertFalse($this->share()->stored($document->fresh()));

        try {
            $this->share()->publish($document->fresh(), $this->admin);
            $this->fail('A missing file was published.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('nothing to publish', $e->getMessage());
        }

        $this->assertNull($document->fresh()->public_token_hash);
    }

    /* ------------------------------------------------------------ the doors */

    public function test_the_share_doors_answer_to_documents_manage(): void
    {
        $document = $this->document();
        $token = $this->share()->publish($document, $this->admin);

        $reader = $this->userWith(['documents.view']);

        $this->actingAs($reader)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeText('Public link')
            ->assertDontSeeText('Rotate the link')
            // The controls are forms, and the page's own navigation may say
            // "Withdraw" somewhere else entirely — so assert on the door, not
            // on the word.
            ->assertDontSee('action="'.route('documents.share.rotate', $document).'"', false)
            ->assertDontSee('action="'.route('documents.share.revoke', $document).'"', false);

        $this->actingAs($reader)->post(route('documents.share.rotate', $document))->assertForbidden();
        $this->actingAs($reader)->post(route('documents.share.revoke', $document))->assertForbidden();

        $this->assertSame(1, (int) $document->fresh()->public_token_version);
        $this->get(route('public.document.show', $token))->assertOk();
    }

    public function test_the_document_list_says_which_files_are_shared(): void
    {
        $shared = $this->document('shared file.pdf');
        $private = $this->document('private file.pdf');

        $this->share()->publish($shared, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSeeText('Shared')
            ->assertSeeText('shared file.pdf')
            ->assertSeeText('private file.pdf')
            ->assertSee(route('documents.show', $shared));
    }

    public function test_another_companys_document_is_not_found(): void
    {
        $elsewhere = new Company([
            'name' => 'Other Concern',
            'country' => 'BD',
            'currency' => 'BDT',
        ]);
        $elsewhere->forceFill(['singleton' => 0])->save();

        $foreign = Document::query()->create([
            'company_id' => $elsewhere->id,
            'branch_id' => null,
            'purpose' => 'attachment',
            'visibility' => 'private',
            'disk' => 'local',
            'path' => 'elsewhere/file.pdf',
            'original_name' => 'their file.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 12,
            'checksum' => str_repeat('a', 64),
        ]);

        $this->actingAs($this->admin)
            ->post(route('documents.share.publish', $foreign))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->public_token_hash);
    }

    public function test_the_public_page_is_rate_limited(): void
    {
        $document = $this->document();
        $token = $this->share()->publish($document, $this->admin);

        for ($hit = 1; $hit <= 30; $hit++) {
            $this->get(route('public.document.show', $token))->assertOk();
        }

        $this->get(route('public.document.show', $token))->assertStatus(429);
    }
}
