<?php

namespace Tests\Feature\Files;

use App\Models\User;
use App\Services\FileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may open an uploaded file.
 *
 * This deserves its own file. A FileAccess bug here is a materially different
 * incident from a leaked profile photograph: the paths this guards will hold
 * photographs of somebody's body, taken in a shop.
 */
class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function storedAvatar(User $user): string
    {
        Storage::fake('local');

        $path = UploadedFile::fake()->image('her.jpg')->store(FileAccess::AVATARS, 'local');
        $user->update(['avatar_url' => $path]);

        return $path;
    }

    public function test_an_avatar_is_readable_by_any_signed_in_account(): void
    {
        $owner = User::factory()->create();
        $path = $this->storedAvatar($owner);

        Sanctum::actingAs(User::factory()->create());

        // Public to signed-in accounts by design: it appears in the directory,
        // beside every review and on a business card.
        $this->get("/api/files/{$path}")->assertOk();
    }

    public function test_a_guessed_avatar_path_finds_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatars/not-anybodys.jpg', 'x');

        Sanctum::actingAs(User::factory()->create());

        // The file is really there; it just belongs to no record.
        $this->get('/api/files/avatars/not-anybodys.jpg')->assertNotFound();
    }

    public function test_a_prefix_the_platform_does_not_write_to_is_refused(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('somewhere-else/secret.txt', 'x');

        Sanctum::actingAs(User::factory()->admin()->create());

        // Refused for an admin too: an admin has reason to read uploads, not to
        // reach anywhere on the disk.
        $this->get('/api/files/somewhere-else/secret.txt')->assertNotFound();
    }

    public function test_signed_out_reaches_no_file_at_all(): void
    {
        $path = $this->storedAvatar(User::factory()->create());

        $this->getJson("/api/files/{$path}")->assertUnauthorized();
    }

    /**
     * The decision from CLAUDE.md section 3, tested before a measurement record
     * can exist. Section 11 gives admins a narrow way in; until it does, and
     * for anyone without it afterwards, the answer is no.
     */
    public function test_an_admin_has_no_blanket_bypass_on_measurements(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('measurements/her-book.jpg', 'x');

        $admin = User::factory()->admin()->create();

        $this->assertFalse(app(FileAccess::class)->allows($admin, 'measurements/her-book.jpg'));

        Sanctum::actingAs($admin);

        // 404, never 403: for a measurement book the existence of the record is
        // itself the sensitive fact.
        $this->get('/api/files/measurements/her-book.jpg')->assertNotFound();
    }

    public function test_an_admin_still_reads_the_prefixes_that_are_not_restricted(): void
    {
        $path = $this->storedAvatar(User::factory()->create());

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->get("/api/files/{$path}")->assertOk();
    }

    /**
     * Prefixes whose owning records arrive in Sections 7, 10 and 11. Listed and
     * refusing, so a section that forgets to wire its resolver gets a dead 404
     * rather than an open door.
     */
    public function test_prefixes_with_no_resolver_yet_refuse_everyone(): void
    {
        Storage::fake('local');

        $access = app(FileAccess::class);
        $tailor = User::factory()->tailor()->create();
        $customer = User::factory()->create();

        foreach ([FileAccess::STEP_VOICE_NOTES, FileAccess::STEP_PHOTOS, FileAccess::MEASUREMENTS] as $prefix) {
            Storage::disk('local')->put("{$prefix}/file.bin", 'x');

            $this->assertFalse($access->allows($tailor, "{$prefix}/file.bin"), $prefix);
            $this->assertFalse($access->allows($customer, "{$prefix}/file.bin"), $prefix);
        }
    }

    public function test_traversal_is_refused_before_anything_touches_the_disk(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach ([
            'avatars/../../.env',
            '../.env',
            '.env',
            // A Windows separator, URL-encoded. This is developed on Windows,
            // where it really is a separator.
            'avatars%5C..%5C..%5C.env',
        ] as $attempt) {
            $this->get('/api/files/'.$attempt)->assertNotFound();
        }
    }

    public function test_an_uploaded_file_is_never_sniffed_into_html(): void
    {
        $path = $this->storedAvatar(User::factory()->create());

        Sanctum::actingAs(User::factory()->create());

        $response = $this->get("/api/files/{$path}")->assertHeader('X-Content-Type-Options', 'nosniff');

        // Private: a shared cache must not hold one person's uploads. Symfony
        // normalises the directive order, so this checks the directive is there
        // rather than how it happens to be spelled.
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control') ?? '');
    }
}
