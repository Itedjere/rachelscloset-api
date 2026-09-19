<?php

namespace Tests\Feature\Steps;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\OrderStep;
use App\Models\ProductionStep;
use App\Models\StepTemplate;
use App\Models\User;
use App\Notifications\OrderReady;
use App\Notifications\StepCompleted;
use App\Services\FileAccess;
use App\Services\Orders\AssembleOrderSteps;
use App\Services\Orders\RecalculateOrderProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The tracker.
 *
 * The two tests the plan singles out live here: renaming a library step must
 * not change a customer's timeline, and replacing a step's recording must
 * leave an existing order's copy playable.
 */
class OrderTrackerTest extends TestCase
{
    use RefreshDatabase;

    private GarmentType $garment;

    private array $steps = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->garment = GarmentType::factory()->create(['name' => 'Agbada']);

        foreach (['Cutting', 'Sewing', 'Pressing'] as $label) {
            $this->steps[$label] = ProductionStep::factory()->create([
                'label' => $label,
                'instructions' => "How to do {$label}.",
            ]);
        }

        StepTemplate::defaultFor($this->garment)->reorder(
            array_map(fn ($s) => $s->id, array_values($this->steps)),
        );
    }

    private function order(?User $tailor = null): Order
    {
        $order = Order::factory()->create([
            'garment_type_id' => $this->garment->id,
        ] + ($tailor ? ['tailor_id' => $tailor->id] : []));

        app(AssembleOrderSteps::class)->handle($order);

        return $order->fresh();
    }

    private function working(): Order
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        return $order->fresh();
    }

    /* ===================================================================== */

    public function test_an_order_is_assembled_from_the_arrangement(): void
    {
        $order = $this->order();

        $this->assertSame(
            ['Cutting', 'Sewing', 'Pressing'],
            $order->steps->pluck('label')->all(),
        );
        $this->assertSame([1, 2, 3], $order->steps->pluck('position')->all());
    }

    /**
     * The list screen reads these counters, so an order that has just been
     * opened has to say "0 of 10" rather than "0 of 0".
     */
    public function test_assembly_fills_in_the_counters(): void
    {
        $order = $this->order();

        $this->assertSame(3, $order->steps_total);
        $this->assertSame(0, $order->steps_completed);
    }

    public function test_assembling_twice_does_not_duplicate_the_checklist(): void
    {
        $order = $this->order();

        app(AssembleOrderSteps::class)->handle($order);

        $this->assertSame(3, $order->fresh()->steps()->count());
    }

    /** A tailor with her own arrangement gets her own, not the default. */
    public function test_a_tailors_own_arrangement_is_what_gets_copied(): void
    {
        $tailor = User::factory()->tailor()->create();

        StepTemplate::firstOrCreate(
            ['garment_type_id' => $this->garment->id, 'owner_id' => $tailor->id],
            ['name' => 'Mine'],
        )->reorder([$this->steps['Sewing']->id, $this->steps['Cutting']->id]);

        $order = $this->order($tailor);

        $this->assertSame(['Sewing', 'Cutting'], $order->steps->pluck('label')->all());
    }

    /**
     * THE SNAPSHOT RULE.
     *
     * The customer read those words. An admin tidying up the library next
     * month must not reach back and rewrite the timeline she already saw.
     */
    public function test_renaming_a_library_step_does_not_change_an_existing_order(): void
    {
        $order = $this->order();

        $this->steps['Cutting']->update([
            'label' => 'Cutting and marking',
            'instructions' => 'Completely different wording.',
        ]);

        $step = $order->fresh()->steps->first();

        $this->assertSame('Cutting', $step->label);
        $this->assertSame('How to do Cutting.', $step->instructions);
    }

    public function test_retiring_a_library_step_does_not_remove_it_from_an_order(): void
    {
        $order = $this->order();

        $this->steps['Sewing']->forceFill(['retired_at' => now()])->save();

        $this->assertSame(3, $order->fresh()->steps()->count());
    }

    /**
     * THE WRITE-ONCE RULE, end to end.
     *
     * Replacing a library recording must leave the copy an order is holding
     * playable -- otherwise an admin re-recording one step silences it for
     * every customer part-way through an order.
     */
    public function test_replacing_a_library_recording_leaves_an_order_still_playable(): void
    {
        Storage::fake('local');

        $first = UploadedFile::fake()->create('one.webm', 10, 'audio/webm')
            ->store(FileAccess::STEP_VOICE_NOTES, 'local');
        $this->steps['Cutting']->setVoiceNote($first);

        // The order copies that path.
        $order = $this->order();
        $this->assertSame($first, $order->steps->first()->voice_note_url);

        // The admin re-records. Nothing in production_steps points at $first now.
        $second = UploadedFile::fake()->create('two.webm', 10, 'audio/webm')
            ->store(FileAccess::STEP_VOICE_NOTES, 'local');
        $this->steps['Cutting']->setVoiceNote($second);

        Storage::disk('local')->assertExists($first);
        $this->assertSame($first, $order->fresh()->steps->first()->voice_note_url);

        // And it is still reachable, because an order_step holds it.
        Sanctum::actingAs($order->customer);
        $this->get("/api/files/{$first}")->assertOk();
    }

    /* ===================================================================== */

    public function test_the_tailor_ticks_a_stage_and_the_customer_is_told(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])
            ->assertOk()
            ->assertJsonPath('data.steps_completed', 1)
            ->assertJsonPath('data.steps.0.complete', true);

        Notification::assertSentTo($order->customer, StepCompleted::class);
    }

    public function test_the_customer_cannot_tick_anything(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->customer);

        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])
            ->assertNotFound();
    }

    public function test_both_sides_can_read_the_list(): void
    {
        $order = $this->working();

        foreach ([$order->customer, $order->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->getJson("/api/orders/{$order->id}/steps")->assertOk()->assertJsonCount(3, 'data');
        }

        Sanctum::actingAs(User::factory()->tailor()->create());
        $this->getJson("/api/orders/{$order->id}/steps")->assertNotFound();
    }

    /** A mis-tap on a small screen is ordinary; she has to be able to fix it. */
    public function test_un_ticking_does_not_tell_the_customer_again(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])->assertOk();
        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => false])->assertOk();
        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])->assertOk();

        // Told on each transition INTO done, not on the correction between.
        Notification::assertSentToTimes($order->customer, StepCompleted::class, 2);
        $this->assertSame(1, $order->fresh()->steps_completed);
    }

    /** Counters are recomputed, never adjusted, so they cannot drift. */
    public function test_progress_is_recomputed_from_scratch(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->tailor);

        foreach ($order->steps as $step) {
            $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])->assertOk();
        }

        $order = $order->fresh();
        $this->assertSame(3, $order->steps_total);
        $this->assertSame(3, $order->steps_completed);

        // Delete one behind the counters' back, then recompute.
        OrderStep::query()->where('order_id', $order->id)->first()->delete();
        app(RecalculateOrderProgress::class)->handle($order);

        $order = $order->fresh();
        $this->assertSame(2, $order->steps_total);
        $this->assertSame(2, $order->steps_completed);
    }

    /**
     * Ticking the last stage is what finishes a garment. Asking for a
     * separate "mark ready" afterwards would be asking twice.
     */
    public function test_ticking_the_last_stage_makes_the_order_ready(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->tailor);

        foreach ($order->steps as $step) {
            $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])->assertOk();
        }

        $order = $order->fresh();

        $this->assertSame(Order::READY, $order->status);
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->collection_deadline);
        Notification::assertSentTo($order->customer, OrderReady::class);

        /*
         * Two, not three. The tick that finished the garment is announced by
         * the ready notice instead -- two lines a second apart saying the
         * same thing is how a useful notification becomes one she swipes away
         * without reading.
         */
        Notification::assertSentToTimes($order->customer, StepCompleted::class, 2);
    }

    public function test_an_order_not_being_worked_on_cannot_be_ticked(): void
    {
        $order = $this->order();  // still pending_payment
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->putJson("/api/orders/{$order->id}/steps/{$step->id}", ['complete' => true])
            ->assertStatus(422);
    }

    public function test_a_step_from_another_order_is_not_found(): void
    {
        $mine = $this->working();
        $hers = $this->working();

        Sanctum::actingAs($mine->tailor);

        $this->putJson("/api/orders/{$mine->id}/steps/{$hers->steps->first()->id}", ['complete' => true])
            ->assertNotFound();
    }
}
