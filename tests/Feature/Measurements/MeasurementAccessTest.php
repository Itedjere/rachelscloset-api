<?php

namespace Tests\Feature\Measurements;

use App\Models\MeasurementSet;
use App\Models\Order;
use App\Models\TailorCustomerLink;
use App\Models\User;
use App\Services\FileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may see somebody's measurements.
 *
 * This file exists on its own because the plan says it should: a FileAccess
 * bug here is a materially different incident from a leaked photograph of a
 * sleeve. Every rule is checked twice -- once against the API and once
 * against the file path -- because the failure that matters is the one where
 * a tailor who cannot open the record can still stream the photograph by
 * knowing its URL.
 */
class MeasurementAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    private MeasurementSet $set;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->customer = User::factory()->customer()->create();
        $this->tailor = User::factory()->tailor()->create();

        $this->set = MeasurementSet::factory()->create([
            'customer_id' => $this->customer->id,
            'recorded_by' => User::factory()->tailor()->create()->id,
        ]);

        Storage::disk('local')->put($this->set->photo_url, 'a photograph of a book page');
    }

    /** Both doors, one question. Every test below calls this. */
    private function assertCanSee(User $user, bool $expected): void
    {
        Sanctum::actingAs($user);

        $this->getJson("/api/measurements/{$this->set->id}")
            ->assertStatus($expected ? 200 : 404);

        $this->get('/api/files/'.$this->set->photo_url)
            ->assertStatus($expected ? 200 : 404);
    }

    private function liveOrder(string $status = Order::IN_PROGRESS): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
        ]);
        $order->forceFill(['status' => $status])->save();

        return $order->fresh();
    }

    /* ===================================================================== */

    public function test_it_is_her_own_body(): void
    {
        $this->assertCanSee($this->customer, true);
    }

    /** The rule the whole file is here to protect. */
    public function test_a_tailor_with_no_link_and_no_order_gets_nothing(): void
    {
        $this->assertCanSee($this->tailor, false);
    }

    public function test_another_customer_gets_nothing(): void
    {
        $this->assertCanSee(User::factory()->customer()->create(), false);
    }

    /**
     * A plain admin gets nothing, which is a deliberate break from
     * BizyFarmers. There is no `measurements.view` permission and no dispute
     * to attach it to, so the honest answer is no.
     */
    public function test_a_plain_admin_gets_nothing(): void
    {
        $this->assertCanSee(User::factory()->admin()->create(), false);
    }

    public function test_an_unauthenticated_request_gets_nothing(): void
    {
        $this->getJson("/api/measurements/{$this->set->id}")->assertUnauthorized();
        $this->get('/api/files/'.$this->set->photo_url)->assertUnauthorized();
    }

    /* ===================================================================== */

    public function test_a_live_order_grants(): void
    {
        $this->liveOrder();

        $this->assertCanSee($this->tailor, true);
    }

    /** Every status somebody could still be working through. */
    public function test_every_live_status_grants(): void
    {
        foreach ([Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY, Order::DISPUTED] as $status) {
            Order::query()->delete();
            $this->liveOrder($status);

            Sanctum::actingAs($this->tailor);
            $this->getJson("/api/measurements/{$this->set->id}")
                ->assertOk("status {$status} should grant access");
        }
    }

    /**
     * A FINISHED order does not keep granting.
     *
     * "She was once my customer, so I keep her measurements forever" turns
     * one job into an indefinite claim on somebody's body, and it accumulates
     * without anybody deciding to.
     */
    public function test_a_finished_order_does_not_keep_granting(): void
    {
        foreach ([Order::COLLECTED, Order::COMPLETED, Order::CANCELLED] as $status) {
            Order::query()->delete();
            $this->liveOrder($status);

            $this->assertCanSee($this->tailor, false);
        }
    }

    /* ===================================================================== */

    public function test_a_granted_link_grants(): void
    {
        TailorCustomerLink::factory()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->assertCanSee($this->tailor, true);
    }

    public function test_a_revoked_link_does_not(): void
    {
        TailorCustomerLink::factory()->revoked()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->assertCanSee($this->tailor, false);
    }

    /**
     * Revoking does not strip a tailor mid-order.
     *
     * The cloth is already cut and she has the numbers on paper. Taking the
     * record away protects nobody and ruins the garment -- so the consent
     * screen says so rather than pretending otherwise.
     */
    public function test_revoking_does_not_strip_a_live_order(): void
    {
        $link = TailorCustomerLink::factory()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);
        $this->liveOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/measurement-access/{$link->id}/revoke")->assertOk();

        $this->assertCanSee($this->tailor, true);
    }

    /** And the consent screen says as much, rather than quietly lying. */
    public function test_the_consent_screen_admits_a_live_order_still_sees(): void
    {
        $link = TailorCustomerLink::factory()->revoked()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);
        $this->liveOrder();

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/measurement-access')
            ->assertOk()
            ->assertJsonPath('data.0.id', $link->id)
            ->assertJsonPath('data.0.granted', false)
            ->assertJsonPath('data.0.can_see_now', true)
            ->assertJsonPath('data.0.has_live_order', true);
    }

    /** A tailor with a live order and no link at all is still listed. */
    public function test_a_live_order_with_no_link_appears_on_the_consent_screen(): void
    {
        $this->liveOrder();

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/measurement-access')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tailor.id', $this->tailor->id)
            ->assertJsonPath('data.0.can_see_now', true);
    }

    public function test_a_customer_cannot_revoke_somebody_elses_link(): void
    {
        $link = TailorCustomerLink::factory()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);

        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson("/api/measurement-access/{$link->id}/revoke")->assertNotFound();
        $this->assertTrue($link->fresh()->isGranted());
    }

    public function test_granting_again_is_one_tap(): void
    {
        $link = TailorCustomerLink::factory()->revoked()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
        ]);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/measurement-access/{$link->id}/grant")->assertOk();

        $this->assertCanSee($this->tailor, true);
    }

    /* ===================================================================== */

    /**
     * An unclaimed profile has consented to nothing, so her measurements stay
     * with the one tailor who took them -- even from a tailor who would
     * otherwise qualify through a live order.
     */
    public function test_an_unclaimed_profile_is_visible_only_to_the_tailor_who_measured(): void
    {
        $walkIn = User::factory()->customer()->unclaimed()->create();

        $hers = MeasurementSet::factory()->create([
            'customer_id' => $walkIn->id,
            'recorded_by' => $this->tailor->id,
        ]);
        Storage::disk('local')->put($hers->photo_url, 'x');

        $other = User::factory()->tailor()->create();
        $order = Order::factory()->create([
            'customer_id' => $walkIn->id,
            'tailor_id' => $other->id,
        ]);
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        // The tailor who measured her: yes.
        Sanctum::actingAs($this->tailor);
        $this->getJson("/api/measurements/{$hers->id}")->assertOk();
        $this->get('/api/files/'.$hers->photo_url)->assertOk();

        // A different tailor, with a live order: still no. Consent has not
        // happened, so cross-tailor history has not begun.
        Sanctum::actingAs($other);
        $this->getJson("/api/measurements/{$hers->id}")->assertNotFound();
        $this->get('/api/files/'.$hers->photo_url)->assertNotFound();
    }

    public function test_the_listing_is_filtered_per_row_for_an_unclaimed_profile(): void
    {
        $walkIn = User::factory()->customer()->unclaimed()->create();
        $other = User::factory()->tailor()->create();

        MeasurementSet::factory()->create(['customer_id' => $walkIn->id, 'recorded_by' => $this->tailor->id]);
        MeasurementSet::factory()->create(['customer_id' => $walkIn->id, 'recorded_by' => $other->id]);

        Sanctum::actingAs($this->tailor);

        $this->getJson("/api/customers/{$walkIn->id}/measurements")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /* ===================================================================== */

    public function test_a_guessed_measurement_path_finds_nothing(): void
    {
        Sanctum::actingAs($this->customer);

        $this->get('/api/files/'.FileAccess::MEASUREMENTS.'/invented.jpg')->assertNotFound();
    }

    /** The tailor records; the photograph is required, the numbers are not. */
    public function test_a_tailor_with_a_live_order_can_record_a_set(): void
    {
        $this->liveOrder();

        Sanctum::actingAs($this->tailor);

        $this->post("/api/customers/{$this->customer->id}/measurements", [
            'photo' => UploadedFile::fake()->image('book.jpg', 1200, 1600),
            'label' => 'Wedding',
            'values' => [
                ['label' => 'Bust', 'value' => '38 1/2', 'unit' => 'in'],
                ['label' => 'Sleeve', 'value' => '23'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Wedding')
            ->assertJsonCount(2, 'data.values')
            ->assertJsonPath('data.values.0.value', '38 1/2');
    }

    public function test_recording_without_a_photograph_is_refused(): void
    {
        $this->liveOrder();

        Sanctum::actingAs($this->tailor);

        $this->postJson("/api/customers/{$this->customer->id}/measurements", ['label' => 'Wedding'])
            ->assertStatus(422);
    }

    public function test_a_tailor_with_no_relationship_cannot_record(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->post("/api/customers/{$this->customer->id}/measurements", [
            'photo' => UploadedFile::fake()->image('book.jpg'),
        ])->assertNotFound();
    }

    /** Her body, her record. A tailor cannot delete somebody's history. */
    public function test_only_the_customer_may_delete_a_set(): void
    {
        $this->liveOrder();

        Sanctum::actingAs($this->tailor);
        $this->deleteJson("/api/measurements/{$this->set->id}")->assertNotFound();

        Sanctum::actingAs($this->customer);
        $this->deleteJson("/api/measurements/{$this->set->id}")->assertOk();

        $this->assertDatabaseMissing('measurement_sets', ['id' => $this->set->id]);
    }
}
