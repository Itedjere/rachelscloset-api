<?php

namespace Tests\Feature\Directory;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\PortfolioItem;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The gallery.
 *
 * Two sources into one table: the tailor's own photographs, so a new profile
 * is worth visiting, and the customer's photographs of herself wearing the
 * finished garment, which are the ones that sell the work.
 */
class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tailor = User::factory()->tailor()->create();
        TailorProfile::factory()->create(['user_id' => $this->tailor->id]);
        $this->customer = User::factory()->customer()->create();

        PlatformSetting::set(PlatformSetting::PORTFOLIO_MAX_OWN, '5');
        PlatformSetting::set(PlatformSetting::PORTFOLIO_MAX_PER_ORDER, '5');
    }

    private function collectedOrder(): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
        ]);
        $order->forceFill(['status' => Order::COLLECTED, 'collected_at' => now()])->save();

        return $order->fresh();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('dress.jpg', 900, 1200);
    }

    /* ===================================================================== */

    public function test_a_tailor_adds_her_own_photographs(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->post('/api/portfolio', ['photo' => $this->photo(), 'caption' => 'Aso-oke set'])
            ->assertCreated()
            ->assertJsonPath('data.caption', 'Aso-oke set')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.hidden', false);

        $item = PortfolioItem::query()->sole();

        $this->assertNull($item->order_id);
        $this->assertSame($this->tailor->id, $item->uploaded_by);
        Storage::disk('local')->assertExists($item->path);
    }

    /** Storage is a real limit on shared hosting. */
    public function test_her_own_uploads_are_capped(): void
    {
        Sanctum::actingAs($this->tailor);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();
        }

        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertStatus(422);

        $this->assertSame(5, PortfolioItem::query()->count());
    }

    public function test_the_cap_is_a_setting_an_admin_can_move(): void
    {
        PlatformSetting::set(PlatformSetting::PORTFOLIO_MAX_OWN, '2');

        Sanctum::actingAs($this->tailor);

        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();
        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();
        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertStatus(422);
    }

    public function test_a_customer_cannot_upload_to_a_profile(): void
    {
        Sanctum::actingAs($this->customer);

        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertNotFound();
    }

    /* ===================================================================== */

    /** The good source: she wore it to a party and photographed it. */
    public function test_the_customer_adds_photographs_to_a_finished_order(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->customer);

        $this->post("/api/orders/{$order->id}/photos", [
            'photo' => $this->photo(),
            'caption' => 'At my sister’s wedding',
        ])->assertCreated();

        $item = PortfolioItem::query()->sole();

        // It lands in the TAILOR's gallery, uploaded by the customer.
        $this->assertSame($this->tailor->id, $item->tailor_id);
        $this->assertSame($order->id, $item->order_id);
        $this->assertSame($this->customer->id, $item->uploaded_by);
    }

    public function test_photographs_cannot_be_added_before_collection(): void
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
        ]);
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        Sanctum::actingAs($this->customer);

        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
            ->assertStatus(422);
    }

    public function test_the_tailor_cannot_upload_on_the_customers_behalf(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->tailor);

        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
            ->assertNotFound();
    }

    public function test_a_stranger_cannot_upload_to_an_order(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs(User::factory()->customer()->create());

        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
            ->assertNotFound();
    }

    public function test_photographs_per_order_are_capped(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->customer);

        for ($i = 0; $i < 5; $i++) {
            $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
                ->assertCreated();
        }

        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
            ->assertStatus(422);
    }

    /** The caps are separate, so one does not eat the other. */
    public function test_the_two_caps_are_independent(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->tailor);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();
        }

        Sanctum::actingAs($this->customer);
        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])
            ->assertCreated();

        $this->assertSame(6, PortfolioItem::query()->where('tailor_id', $this->tailor->id)->count());
    }

    /* ===================================================================== */

    /** Her shopfront. She may take a customer's photograph off it. */
    public function test_the_tailor_can_hide_a_customers_photograph(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->customer);
        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])->assertCreated();

        $item = PortfolioItem::query()->sole();

        Sanctum::actingAs($this->tailor);
        $this->postJson("/api/portfolio/{$item->id}/hide")->assertOk();

        $this->assertFalse($item->fresh()->isVisible());

        // And put it back.
        $this->postJson("/api/portfolio/{$item->id}/show")->assertOk();
        $this->assertTrue($item->fresh()->isVisible());
    }

    /** But she may not delete somebody else's photograph of her own work. */
    public function test_the_tailor_cannot_delete_a_customers_photograph(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->customer);
        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])->assertCreated();

        $item = PortfolioItem::query()->sole();

        Sanctum::actingAs($this->tailor);
        $this->deleteJson("/api/portfolio/{$item->id}")->assertNotFound();

        $this->assertDatabaseHas('portfolio_items', ['id' => $item->id]);
    }

    public function test_the_customer_can_delete_her_own(): void
    {
        $order = $this->collectedOrder();

        Sanctum::actingAs($this->customer);
        $this->post("/api/orders/{$order->id}/photos", ['photo' => $this->photo()])->assertCreated();

        $item = PortfolioItem::query()->sole();

        $this->deleteJson("/api/portfolio/{$item->id}")->assertOk();

        $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
        Storage::disk('local')->assertMissing($item->path);
    }

    /* ===================================================================== */

    /** Public: a stranger scanning a QR code has no account. */
    public function test_a_gallery_photograph_is_served_to_anybody(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();

        $item = PortfolioItem::query()->sole();

        $this->app['auth']->forgetGuards();

        $this->get('/photo/'.basename($item->path))->assertOk();
    }

    /** Hiding it takes it offline, not just off the page. */
    public function test_a_hidden_photograph_stops_being_reachable(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->post('/api/portfolio', ['photo' => $this->photo()])->assertCreated();

        $item = PortfolioItem::query()->sole();
        $this->postJson("/api/portfolio/{$item->id}/hide")->assertOk();

        $this->app['auth']->forgetGuards();

        $this->get('/photo/'.basename($item->path))->assertNotFound();
    }

    public function test_a_guessed_photo_path_finds_nothing(): void
    {
        $this->get('/photo/invented.jpg')->assertNotFound();
    }
}
