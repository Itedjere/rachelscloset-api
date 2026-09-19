<?php

namespace Tests\Feature\Steps;

use App\Models\ProductionStep;
use App\Models\User;
use App\Services\FileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_an_admin_can_add_a_step(): void
    {
        $this->admin();

        $this->postJson('/api/admin/steps', ['label' => 'Cutting', 'instructions' => 'Cut the pieces.'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Cutting')
            ->assertJsonPath('data.has_voice_note', false);
    }

    public function test_a_tailor_cannot_touch_the_library(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->postJson('/api/admin/steps', ['label' => 'Mine'])->assertForbidden();
        $this->getJson('/api/admin/steps')->assertForbidden();
    }

    public function test_recording_a_voice_note(): void
    {
        Storage::fake('local');
        $this->admin();

        $step = ProductionStep::factory()->create();

        $this->postJson("/api/admin/steps/{$step->id}/voice-note", [
            'voice_note' => UploadedFile::fake()->create('note.webm', 40, 'audio/webm'),
        ])->assertOk()->assertJsonPath('data.has_voice_note', true);

        $path = $step->fresh()->voice_note_url;

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith(FileAccess::STEP_VOICE_NOTES.'/', $path);
    }

    /**
     * The rule the whole library turns on.
     *
     * An order snapshots the recording path it was told at assembly time, so
     * deleting the previous file on replace would silence that step for every
     * customer part-way through an order. One plausible-looking tidy-up in the
     * controller breaks this, which is why it is tested before the orders that
     * depend on it exist.
     */
    public function test_replacing_a_voice_note_leaves_the_old_recording_on_disk(): void
    {
        Storage::fake('local');
        $this->admin();

        $step = ProductionStep::factory()->create();

        $this->postJson("/api/admin/steps/{$step->id}/voice-note", [
            'voice_note' => UploadedFile::fake()->create('first.webm', 40, 'audio/webm'),
        ])->assertOk();
        $first = $step->fresh()->voice_note_url;

        $this->postJson("/api/admin/steps/{$step->id}/voice-note", [
            'voice_note' => UploadedFile::fake()->create('second.webm', 40, 'audio/webm'),
        ])->assertOk();
        $second = $step->fresh()->voice_note_url;

        $this->assertNotSame($first, $second);

        Storage::disk('local')->assertExists($second);
        // The point of the test.
        Storage::disk('local')->assertExists($first);
    }

    public function test_a_document_pretending_to_be_audio_is_refused(): void
    {
        Storage::fake('local');
        $this->admin();

        $step = ProductionStep::factory()->create();

        $this->postJson("/api/admin/steps/{$step->id}/voice-note", [
            'voice_note' => UploadedFile::fake()->create('note.webm', 40, 'application/pdf'),
        ])->assertJsonValidationErrors('voice_note');
    }

    public function test_a_recording_is_playable_by_anybody_signed_in(): void
    {
        Storage::fake('local');

        $path = UploadedFile::fake()->create('n.webm', 10, 'audio/webm')
            ->store(FileAccess::STEP_VOICE_NOTES, 'local');
        ProductionStep::factory()->create(['voice_note_url' => $path]);

        // A customer has to hear what she is being told has happened, and a
        // tailor has to hear a step to tick it off.
        foreach ([User::factory()->create(), User::factory()->tailor()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->get("/api/files/{$path}")->assertOk();
        }
    }

    public function test_a_guessed_recording_path_finds_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('step-voice-notes/not-a-step.webm', 'x');

        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->get('/api/files/step-voice-notes/not-a-step.webm')->assertNotFound();
    }

    public function test_a_step_is_retired_not_deleted(): void
    {
        $this->admin();
        $step = ProductionStep::factory()->create();

        $this->postJson("/api/admin/steps/{$step->id}/retire", ['retired' => true])
            ->assertOk()
            ->assertJsonPath('data.retired', true);

        $this->assertDatabaseHas('production_steps', ['id' => $step->id]);
        $this->assertNotNull($step->fresh()->retired_at);

        // And can be brought back.
        $this->postJson("/api/admin/steps/{$step->id}/retire", ['retired' => false])
            ->assertOk()
            ->assertJsonPath('data.retired', false);
    }

    public function test_the_library_hides_retired_steps_unless_asked(): void
    {
        $this->admin();
        ProductionStep::factory()->create(['label' => 'Live one']);
        ProductionStep::factory()->retired()->create(['label' => 'Old one']);

        $this->getJson('/api/admin/steps')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/steps?include_retired=1')->assertOk()->assertJsonCount(2, 'data');
    }
}
