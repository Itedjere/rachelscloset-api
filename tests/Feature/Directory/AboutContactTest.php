<?php

namespace Tests\Feature\Directory;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * About us and Contact us.
 *
 * The two things worth pinning: the About page counts the real house through
 * the directory's own gate, and the Contact page's number is the help line
 * from Settings -- never a copy typed into the page.
 */
class AboutContactTest extends TestCase
{
    use RefreshDatabase;

    private function tailor(string $name, string $state, bool $paid = true): User
    {
        $user = User::factory()->tailor()->create();

        TailorProfile::factory()->create([
            'user_id' => $user->id,
            'business_name' => $name,
            'slug' => TailorProfile::slugFor($name),
            'state' => $state,
        ]);

        Subscription::forTailor($user)->forceFill([
            'current_period_end' => $paid ? now()->addDays(30) : now()->subDays(60),
            'grace_ends_at' => $paid ? now()->addDays(37) : now()->subDays(50),
        ])->save();

        return $user;
    }

    public function test_both_pages_render_for_anybody(): void
    {
        $this->get('/about')->assertOk()->assertSee('We make the wait');
        $this->get('/contact')->assertOk()->assertSee('Talk to a');
    }

    /** Counted through the directory's gate: a lapsed tailor is not "in the house". */
    public function test_about_counts_only_the_listed_house(): void
    {
        $listed = $this->tailor('Listed Lace', 'Lagos');
        $this->tailor('Second House', 'Kano');
        $lapsed = $this->tailor('Lapsed Loom', 'Oyo', paid: false);

        Order::factory()->create(['tailor_id' => $listed->id])->forceFill(['status' => Order::IN_PROGRESS])->save();
        Order::factory()->create(['tailor_id' => $lapsed->id])->forceFill(['status' => Order::IN_PROGRESS])->save();
        // A finished garment counts whoever made it.
        Order::factory()->create(['tailor_id' => $lapsed->id])->forceFill(['status' => Order::COMPLETED])->save();

        $this->get('/about')
            ->assertOk()
            ->assertViewHas('house', ['tailors' => 2, 'states' => 2, 'in_the_making' => 1, 'finished' => 1]);
    }

    /** No house yet: no band of zeroes arguing against the page. */
    public function test_about_hides_the_numbers_when_nobody_is_listed(): void
    {
        $this->get('/about')->assertOk()->assertDontSee('class="stats"', false);

        $this->tailor('First House', 'Lagos');

        $this->get('/about')->assertOk()->assertSee('class="stats"', false);
    }

    public function test_contact_uses_the_help_line_from_settings(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '08152070480');

        $this->get('/contact')
            ->assertOk()
            ->assertSee('tel:08152070480', false)
            ->assertSee('0815 207 0480')
            ->assertSee('https://wa.me/2348152070480?text=', false);
    }

    /** Unset, it says so -- never an empty button. */
    public function test_contact_without_a_help_line_says_so(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '');

        $this->get('/contact')
            ->assertOk()
            ->assertSee('Our help line is being set up')
            ->assertDontSee('tel:', false);
    }

    public function test_the_footer_and_sitemap_link_both_pages(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(route('about'), false)
            ->assertSee(route('contact'), false);

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(route('about'), false)
            ->assertSee(route('contact'), false);
    }
}
