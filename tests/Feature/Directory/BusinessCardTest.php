<?php

namespace Tests\Feature\Directory;

use App\Models\TailorProfile;
use App\Models\User;
use App\Services\Qr\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The QR business card.
 *
 * The address on it is printed on cardboard that cannot be reissued, so the
 * two things that matter are that the slug never moves and that the page it
 * points at never dies.
 */
class BusinessCardTest extends TestCase
{
    use RefreshDatabase;

    private function tailorWithProfile(string $name = 'Mama Ngozi Couture'): User
    {
        $user = User::factory()->tailor()->create();

        TailorProfile::factory()->create([
            'user_id' => $user->id,
            'business_name' => $name,
            'slug' => TailorProfile::slugFor($name),
            'state' => 'Lagos',
            'location' => 'Ikeja',
        ]);

        return $user;
    }

    /* ===================================================================== */

    public function test_the_card_carries_the_words_and_the_grid(): void
    {
        $tailor = $this->tailorWithProfile();

        Sanctum::actingAs($tailor);

        $response = $this->getJson('/api/business-card')->assertOk();

        $data = $response->json('data');

        $this->assertSame('Mama Ngozi Couture', $data['business_name']);
        $this->assertSame('Ikeja, Lagos', $data['location']);
        $this->assertStringContainsString('/t/mama-ngozi-couture', $data['url']);

        // No scheme on the printed label: shorter, and nobody types it in.
        $this->assertStringNotContainsString('http', $data['url_label']);

        // A square grid of 0s and 1s, with a finder pattern in the corner.
        $this->assertIsArray($data['qr']);
        $this->assertCount(strlen($data['qr'][0]), $data['qr']);
        $this->assertSame('1111111', substr($data['qr'][0], 0, 7));
    }

    public function test_a_customer_has_no_card(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/business-card')->assertNotFound();
    }

    public function test_a_tailor_without_a_profile_is_told_why(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->getJson('/api/business-card')->assertStatus(422);
    }

    /**
     * The address on the card must survive a rename.
     *
     * Changing a slug does not merely break a bookmark: it turns cardboard
     * already in somebody's purse into a dead end with no way to reissue it.
     */
    public function test_renaming_the_business_does_not_move_the_address(): void
    {
        $tailor = $this->tailorWithProfile();

        Sanctum::actingAs($tailor);
        $before = $this->getJson('/api/business-card')->json('data.url');

        $tailor->tailorProfile->update(['business_name' => 'Something Else Entirely']);

        $after = $this->getJson('/api/business-card')->json('data.url');

        $this->assertSame($before, $after);
        $this->get($after)->assertOk();
    }

    /* ===================================================================== */

    /**
     * THE RULE THIS SECTION EXISTS FOR.
     *
     * A 404 on a printed card is a permanent physical failure, and it reads
     * as "this platform is broken" rather than "this tailor is unavailable".
     * An unlisted tailor gets a quiet page and a way onwards instead.
     */
    public function test_an_unlisted_tailors_card_still_resolves(): void
    {
        $tailor = $this->tailorWithProfile();
        $slug = $tailor->tailorProfile->slug;

        $tailor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->get("/t/{$slug}")
            ->assertOk()
            ->assertSee('Mama Ngozi Couture')
            ->assertSee('not listed at the moment')
            // The one thing that still helps somebody holding the card.
            ->assertSee('Find another tailor')
            // But it must not be ranked for her name.
            ->assertSee('noindex', false);
    }

    /** She is still absent from the listing and the sitemap. */
    public function test_an_unlisted_tailor_is_still_out_of_the_directory(): void
    {
        $tailor = $this->tailorWithProfile('Quietly Gone');
        $tailor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->get('/tailors')->assertOk()->assertDontSee('Quietly Gone');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($tailor->tailorProfile->slug);
    }

    public function test_an_unknown_slug_is_still_a_404(): void
    {
        $this->get('/t/never-existed')->assertNotFound();
    }

    /* ===================================================================== */

    /** Print gets the sparser code; a screen gets the redundant one. */
    public function test_print_and_screen_use_different_correction_levels(): void
    {
        $qr = app(QrCode::class);
        $url = 'https://rachelscloset.com.ng/t/mama-ngozi-couture';

        $print = $qr->matrix($url, QrCode::PRINT);
        $screen = $qr->matrix($url, QrCode::SCREEN);

        $this->assertLessThan(
            count($screen),
            count($print),
            'level M must produce a sparser grid than level H',
        );
    }

    /** SVG, never a raster: a PNG writer needs GD and can fail on this host. */
    public function test_the_svg_needs_no_image_extension(): void
    {
        $svg = app(QrCode::class)->svg('https://example.test/t/somebody');

        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);
    }
}
