<?php

namespace Tests\Feature\Files;

use App\Models\Order;
use App\Models\TailorCustomerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every avatar in an API response is the ROUTED url, never the stored path.
 *
 * Uploads live on the private disk and stream through FileController, so a
 * bare `avatars/abc.jpg` is a broken image. Three endpoints shipped that bare
 * path -- the consent screen, the admin's People list and order reviews --
 * because each built its own array instead of going through StoredFile::url.
 */
class AvatarUrlTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tailor = User::factory()->tailor()->create(['avatar_url' => 'avatars/ngozi.jpg']);
    }

    public function test_the_consent_screen_routes_a_linked_tailors_avatar(): void
    {
        $customer = User::factory()->customer()->create();
        TailorCustomerLink::factory()->create(['tailor_id' => $this->tailor->id, 'customer_id' => $customer->id]);

        Sanctum::actingAs($customer);

        $this->getJson('/api/measurement-access')
            ->assertOk()
            ->assertJsonPath('data.0.tailor.avatar_url', '/api/files/avatars/ngozi.jpg');
    }

    /** The other kind of row on that screen: a live order, with no link. */
    public function test_the_consent_screen_routes_a_working_tailors_avatar(): void
    {
        $customer = User::factory()->customer()->create();
        Order::factory()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $customer->id,
            'status' => Order::IN_PROGRESS,
        ]);

        Sanctum::actingAs($customer);

        $this->getJson('/api/measurement-access')
            ->assertOk()
            ->assertJsonPath('data.0.tailor.avatar_url', '/api/files/avatars/ngozi.jpg');
    }

    public function test_the_admin_people_list_routes_avatars(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $row = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))
            ->firstWhere('id', $this->tailor->id);

        $this->assertSame('/api/files/avatars/ngozi.jpg', $row['avatar_url']);
    }
}
