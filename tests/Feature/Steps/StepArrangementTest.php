<?php

namespace Tests\Feature\Steps;

use App\Models\GarmentType;
use App\Models\ProductionStep;
use App\Models\StepTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepArrangementTest extends TestCase
{
    use RefreshDatabase;

    private GarmentType $garment;

    /** @var array<int, ProductionStep> */
    private array $steps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->garment = GarmentType::factory()->create(['name' => 'Agbada']);

        foreach (['Cutting', 'Sewing', 'Pressing', 'Beading'] as $label) {
            $this->steps[$label] = ProductionStep::factory()->create(['label' => $label]);
        }
    }

    private function ids(string ...$labels): array
    {
        return array_map(fn (string $l) => $this->steps[$l]->id, $labels);
    }

    private function labelsFrom($response): array
    {
        return array_column($response->json('data.steps'), 'label');
    }

    public function test_an_admin_sets_the_default_arrangement(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Cutting', 'Sewing', 'Pressing'),
        ])->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertSame(['Cutting', 'Sewing', 'Pressing'], $this->labelsFrom($response));
        $this->assertSame([1, 2, 3], array_column($response->json('data.steps'), 'position'));
    }

    /**
     * The whole ordering API: PUT the complete array.
     *
     * A swap, a move, an insertion and a removal are all this one call, which
     * is why there is no "move up" endpoint for the arrows to hit.
     */
    public function test_reordering_is_a_whole_array_and_renumbers_densely(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Cutting', 'Sewing', 'Pressing'),
        ])->assertOk();

        // Sewing moved to the front, Beading inserted, Pressing dropped.
        $response = $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Sewing', 'Beading', 'Cutting'),
        ])->assertOk();

        $this->assertSame(['Sewing', 'Beading', 'Cutting'], $this->labelsFrom($response));
        $this->assertSame([1, 2, 3], array_column($response->json('data.steps'), 'position'));
    }

    public function test_sending_the_same_order_twice_changes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $payload = ['steps' => $this->ids('Cutting', 'Sewing')];

        $first = $this->putJson("/api/garment-types/{$this->garment->id}/steps", $payload)->assertOk();
        $second = $this->putJson("/api/garment-types/{$this->garment->id}/steps", $payload)->assertOk();

        // Idempotent, so a retry on a flaky connection cannot corrupt it.
        $this->assertSame($this->labelsFrom($first), $this->labelsFrom($second));
        $this->assertSame(2, StepTemplate::defaultFor($this->garment)->items()->count());
    }

    public function test_an_empty_array_clears_the_arrangement(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson("/api/garment-types/{$this->garment->id}/steps", ['steps' => $this->ids('Cutting')])->assertOk();

        $this->putJson("/api/garment-types/{$this->garment->id}/steps", ['steps' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.steps');
    }

    public function test_a_step_cannot_appear_twice(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $cutting = $this->steps['Cutting']->id;

        $response = $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => [$cutting, $cutting, $this->steps['Sewing']->id],
        ])->assertOk();

        $this->assertSame(['Cutting', 'Sewing'], $this->labelsFrom($response));
    }

    public function test_a_retired_step_cannot_be_added(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $old = ProductionStep::factory()->retired()->create();

        $this->putJson("/api/garment-types/{$this->garment->id}/steps", ['steps' => [$old->id]])
            ->assertJsonValidationErrors('steps.0');
    }

    /* ===================================================================== */

    public function test_a_tailor_sees_the_default_until_she_saves_her_own(): void
    {
        $admin = User::factory()->admin()->create();
        $tailor = User::factory()->tailor()->create();

        Sanctum::actingAs($admin);
        $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Cutting', 'Sewing'),
        ])->assertOk();

        Sanctum::actingAs($tailor);
        $response = $this->getJson("/api/garment-types/{$this->garment->id}/steps")->assertOk();

        $this->assertSame(['Cutting', 'Sewing'], $this->labelsFrom($response));
        $this->assertFalse($response->json('data.is_own'));
    }

    public function test_a_tailor_saving_her_own_does_not_touch_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $tailor = User::factory()->tailor()->create();

        Sanctum::actingAs($admin);
        $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Cutting', 'Sewing'),
        ])->assertOk();

        Sanctum::actingAs($tailor);
        $hers = $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Beading', 'Cutting', 'Sewing', 'Pressing'),
        ])->assertOk();

        $this->assertTrue($hers->json('data.is_own'));
        $this->assertSame(['Beading', 'Cutting', 'Sewing', 'Pressing'], $this->labelsFrom($hers));

        // The admin's default is untouched.
        Sanctum::actingAs($admin);
        $default = $this->getJson("/api/garment-types/{$this->garment->id}/steps")->assertOk();
        $this->assertSame(['Cutting', 'Sewing'], $this->labelsFrom($default));
    }

    public function test_one_tailor_cannot_see_or_change_another_tailors_arrangement(): void
    {
        $hers = User::factory()->tailor()->create();
        $mine = User::factory()->tailor()->create();

        Sanctum::actingAs($hers);
        $this->putJson("/api/garment-types/{$this->garment->id}/steps", [
            'steps' => $this->ids('Beading'),
        ])->assertOk();

        Sanctum::actingAs($mine);
        $response = $this->getJson("/api/garment-types/{$this->garment->id}/steps")->assertOk();

        // She gets the default, not the other tailor's arrangement.
        $this->assertFalse($response->json('data.is_own'));
        $this->assertNotContains('Beading', $this->labelsFrom($response));
    }

    public function test_a_tailor_can_go_back_to_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $tailor = User::factory()->tailor()->create();

        Sanctum::actingAs($admin);
        $this->putJson("/api/garment-types/{$this->garment->id}/steps", ['steps' => $this->ids('Cutting')])->assertOk();

        Sanctum::actingAs($tailor);
        $this->putJson("/api/garment-types/{$this->garment->id}/steps", ['steps' => $this->ids('Beading')])->assertOk();

        $response = $this->deleteJson("/api/garment-types/{$this->garment->id}/steps")->assertOk();

        $this->assertFalse($response->json('data.is_own'));
        $this->assertSame(['Cutting'], $this->labelsFrom($response));
    }

    public function test_every_new_garment_type_is_born_with_a_default_arrangement(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/garment-types', ['name' => 'Kaftan'])->assertCreated();

        $kaftan = GarmentType::where('name', 'Kaftan')->sole();

        // So there is never a garment a tailor cannot get steps for.
        $this->assertDatabaseHas('step_templates', [
            'garment_type_id' => $kaftan->id,
            'owner_id' => null,
        ]);
    }

    public function test_a_garment_types_slug_survives_a_rename(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $created = $this->postJson('/api/admin/garment-types', ['name' => 'Ankara dress'])->assertCreated();
        $id = $created->json('data.id');
        $slug = $created->json('data.slug');

        $this->putJson("/api/admin/garment-types/{$id}", ['name' => 'Ankara gown'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ankara gown')
            // It ends up in a URL somebody keeps. A rename changes the name.
            ->assertJsonPath('data.slug', $slug);
    }
}
