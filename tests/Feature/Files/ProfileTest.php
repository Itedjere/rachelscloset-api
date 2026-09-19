<?php

namespace Tests\Feature\Files;

use App\Models\User;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_an_avatar(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ])->assertOk();

        $path = $user->fresh()->avatar_url;

        Storage::disk('local')->assertExists($path);

        // What the client gets back is the routed URL, never the disk path --
        // the file is not reachable any other way.
        $this->assertSame("/api/files/{$path}", $response->json('data.avatar_url'));
        $this->assertStringStartsWith('avatars/', $path);
    }

    public function test_replacing_an_avatar_removes_the_old_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('one.jpg')]);
        $first = $user->fresh()->avatar_url;

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('two.jpg')]);
        $second = $user->fresh()->avatar_url;

        $this->assertNotSame($first, $second);

        // An avatar is decoration and nothing snapshots it, so the old file is
        // only cost. Section 7's voice notes are deliberately the opposite.
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_removing_an_avatar_goes_back_to_initials(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')]);
        $path = $user->fresh()->avatar_url;

        $this->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_a_pdf_renamed_to_jpg_is_refused(): void
    {
        Storage::fake('local');

        Sanctum::actingAs(User::factory()->create());

        // The extension says image; the content does not. Validation reads the
        // content, which is the only version of this check worth having.
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('me.jpg', 40, 'application/pdf'),
        ])->assertJsonValidationErrors('avatar');
    }

    public function test_the_size_limit_never_promises_more_than_php_accepts(): void
    {
        Storage::fake('local');

        Sanctum::actingAs(User::factory()->create());

        $overLimit = min(2048, UploadLimits::maxKilobytes()) + 64;

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size($overLimit),
        ])->assertJsonValidationErrors('avatar');
    }

    public function test_the_name_can_be_changed(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'Ada Obi'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Obi');
    }

    public function test_the_phone_number_cannot_be_changed_here(): void
    {
        $user = User::factory()->create(['phone' => '08031234567']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'Ada', 'phone' => '08039999999'])->assertOk();

        // It is the username. Moving it needs the claim flow from Section 11.
        $this->assertSame('08031234567', $user->fresh()->phone);
    }

    public function test_an_email_is_optional_and_a_blank_one_is_stored_as_null(): void
    {
        $user = User::factory()->withEmail()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => $user->name, 'email' => ''])->assertOk();

        // Null rather than an empty string, or the second account to clear its
        // address would collide on the unique index.
        $this->assertNull($user->fresh()->email);
    }

    public function test_an_address_somebody_else_holds_is_refused(): void
    {
        User::factory()->withEmail()->create(['email' => 'taken@example.test']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => $user->name, 'email' => 'taken@example.test'])
            ->assertJsonValidationErrors('email');
    }

    public function test_keeping_your_own_address_is_not_a_collision_with_yourself(): void
    {
        $user = User::factory()->withEmail()->create(['email' => 'mine@example.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'New Name', 'email' => 'mine@example.test'])
            ->assertOk();
    }

    public function test_omitting_the_address_leaves_it_alone(): void
    {
        $user = User::factory()->withEmail()->create(['email' => 'mine@example.test']);
        Sanctum::actingAs($user);

        // Absent is not the same answer as blank. A partial update must not be
        // able to strip the one recovery route an account has.
        $this->putJson('/api/profile', ['name' => 'New Name'])->assertOk();

        $this->assertSame('mine@example.test', $user->fresh()->email);
    }

    public function test_the_server_says_what_it_will_accept(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonStructure(['data' => ['max_upload_kb', 'max_upload_label', 'push' => ['enabled', 'public_key']]]);
    }
}
