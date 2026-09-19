<?php

namespace Tests\Feature\Steps;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\OrderStep;
use App\Models\OrderStepPhoto;
use App\Models\ProductionStep;
use App\Models\StepTemplate;
use App\Models\User;
use App\Services\Orders\AssembleOrderSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proof that a stage happened.
 *
 * The tracker lets a tailor claim; this lets her show. The rules that matter
 * are about who may look (only the two people on the order) and when proof
 * may be withdrawn (not after the customer has the garment).
 */
class StepPhotoTest extends TestCase
{
    use RefreshDatabase;

    private GarmentType $garment;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');

        $this->garment = GarmentType::factory()->create();

        $steps = collect(['Cutting', 'Sewing'])
            ->map(fn ($label) => ProductionStep::factory()->create(['label' => $label]));

        StepTemplate::defaultFor($this->garment)->reorder($steps->pluck('id')->all());
    }

    private function working(): Order
    {
        $order = Order::factory()->create(['garment_type_id' => $this->garment->id]);
        app(AssembleOrderSteps::class)->handle($order);
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        return $order->fresh();
    }

    private function jpeg(): UploadedFile
    {
        return UploadedFile::fake()->image('stage.jpg', 800, 600);
    }

    private function upload(Order $order, OrderStep $step): TestResponse
    {
        return $this->post(
            "/api/orders/{$order->id}/steps/{$step->id}/photos",
            ['photo' => $this->jpeg()],
        );
    }

    /* ===================================================================== */

    public function test_the_tailor_photographs_a_stage(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->upload($order, $step)
            ->assertCreated()
            ->assertJsonPath('data.deleted', null)
            ->assertJsonStructure(['data' => ['id', 'url', 'created_at']]);

        $photo = OrderStepPhoto::query()->sole();

        $this->assertSame($step->id, $photo->order_step_id);
        // Copied off the step, not taken from the request.
        $this->assertSame($order->id, $photo->order_id);
        $this->assertSame($order->tailor_id, $photo->uploaded_by);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_the_customer_cannot_upload_proof_of_her_own_order(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->customer);

        $this->upload($order, $order->steps->first())->assertNotFound();
    }

    public function test_a_stranger_cannot_upload(): void
    {
        $order = $this->working();

        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->upload($order, $order->steps->first())->assertNotFound();
    }

    public function test_a_step_from_another_order_is_not_found(): void
    {
        $mine = $this->working();
        $hers = $this->working();

        Sanctum::actingAs($mine->tailor);

        $this->upload($mine, $hers->steps->first())->assertNotFound();
    }

    /* ===================================================================== */

    /**
     * The counter the review gate reads.
     *
     * Stages carrying a photograph, not photographs -- three pictures of one
     * sleeve is one stage proved.
     */
    public function test_the_proof_counter_counts_stages_not_photographs(): void
    {
        $order = $this->working();
        $first = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->upload($order, $first)->assertCreated();
        $this->upload($order, $first)->assertCreated();

        $this->assertSame(1, $order->fresh()->steps_with_photo);

        $this->upload($order, $order->steps->last())->assertCreated();

        $this->assertSame(2, $order->fresh()->steps_with_photo);
    }

    public function test_the_counter_is_recomputed_when_a_photograph_is_removed(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        $this->upload($order, $step)->assertCreated();
        $this->assertSame(1, $order->fresh()->steps_with_photo);

        $photo = OrderStepPhoto::query()->sole();

        $this->deleteJson("/api/orders/{$order->id}/steps/{$step->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertSame(0, $order->fresh()->steps_with_photo);
        Storage::disk('local')->assertMissing($photo->path);
    }

    /** Storage is a real limit on a shared host, not a theoretical one. */
    public function test_a_stage_cannot_carry_more_than_the_cap(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);

        for ($i = 0; $i < OrderStepPhoto::MAX_PER_STEP; $i++) {
            $this->upload($order, $step)->assertCreated();
        }

        $this->upload($order, $step)->assertStatus(422);

        $this->assertSame(OrderStepPhoto::MAX_PER_STEP, $step->photos()->count());
    }

    public function test_only_a_photograph_is_accepted(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->tailor);

        $this->post("/api/orders/{$order->id}/steps/{$order->steps->first()->id}/photos", [
            'photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
        ])->assertStatus(422);
    }

    /* ===================================================================== */

    public function test_proof_cannot_be_added_before_work_starts(): void
    {
        $order = Order::factory()->create(['garment_type_id' => $this->garment->id]);
        app(AssembleOrderSteps::class)->handle($order);
        $order = $order->fresh();

        Sanctum::actingAs($order->tailor);

        $this->upload($order, $order->steps->first())->assertStatus(422);
    }

    /**
     * Proof cannot be withdrawn once the customer has the garment. A blurred
     * picture of the wrong sleeve is an ordinary mistake; a photograph
     * removed after collection is evidence leaving a record she may be about
     * to review.
     */
    public function test_proof_cannot_be_removed_once_the_order_is_collected(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);
        $this->upload($order, $step)->assertCreated();

        $photo = OrderStepPhoto::query()->sole();
        $order->forceFill(['status' => Order::COLLECTED])->save();

        $this->deleteJson("/api/orders/{$order->id}/steps/{$step->id}/photos/{$photo->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('order_step_photos', ['id' => $photo->id]);
        Storage::disk('local')->assertExists($photo->path);
    }

    /* ===================================================================== */

    /** Both sides see it. Being shown the work is the point of taking it. */
    public function test_both_parties_can_open_a_proof_photograph(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);
        $this->upload($order, $step)->assertCreated();

        $path = OrderStepPhoto::query()->sole()->path;

        foreach ([$order->customer, $order->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->get("/api/files/{$path}")->assertOk();
        }
    }

    /**
     * And nobody else. Unlike a library voice note, which is the same
     * recording for everybody, this is one customer's cloth on one tailor's
     * table.
     */
    public function test_a_signed_in_stranger_cannot_open_it(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);
        $this->upload($order, $step)->assertCreated();

        $path = OrderStepPhoto::query()->sole()->path;

        // 404, not 403: whether a file exists is itself worth not confirming.
        Sanctum::actingAs(User::factory()->tailor()->create());
        $this->get("/api/files/{$path}")->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/files/{$path}")->assertNotFound();
    }

    /** Proof of work is the dispute material the admin rule was written for. */
    public function test_an_admin_can_open_it(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->tailor);
        $this->upload($order, $order->steps->first())->assertCreated();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->get('/api/files/'.OrderStepPhoto::query()->sole()->path)->assertOk();
    }

    /** A path nobody uploaded is worthless even to somebody on an order. */
    public function test_a_guessed_path_finds_nothing(): void
    {
        $order = $this->working();

        Sanctum::actingAs($order->tailor);

        $this->get('/api/files/step-photos/made-up.jpg')->assertNotFound();
    }

    /* ===================================================================== */

    public function test_photographs_come_back_with_the_steps(): void
    {
        $order = $this->working();
        $step = $order->steps->first();

        Sanctum::actingAs($order->tailor);
        $this->upload($order, $step)->assertCreated();

        Sanctum::actingAs($order->customer);

        $this->getJson("/api/orders/{$order->id}/steps")
            ->assertOk()
            ->assertJsonCount(1, 'data.0.photos')
            ->assertJsonCount(0, 'data.1.photos');

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.steps.0.photos')
            ->assertJsonPath('data.steps_with_photo', 1);
    }
}
