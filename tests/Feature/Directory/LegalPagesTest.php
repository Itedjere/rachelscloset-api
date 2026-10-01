<?php

namespace Tests\Feature\Directory;

use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Privacy, terms, and the footer's way to reach a person.
 *
 * The terms quote the platform's own numbers, so the one thing worth pinning
 * is that they follow the settings rather than a copy typed into the page.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_pages_render_and_say_they_are_drafts(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('This is a draft.');
        $this->get('/terms')->assertOk()->assertSee('This is a draft.');
    }

    /** Change a number in Settings, and the terms say the new one. */
    public function test_the_terms_follow_the_live_settings(): void
    {
        PlatformSetting::set(PlatformSetting::ESCROW_HOLD_DAYS, '5');
        PlatformSetting::set(PlatformSetting::COLLECTION_DEADLINE_DAYS, '10');

        $this->get('/terms')
            ->assertOk()
            ->assertSee('paid 5 days after the customer has the', false)
            ->assertSee('10 days to collect', false);
    }

    public function test_the_footer_carries_the_help_line_and_the_legal_pages(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '08152070480');

        $this->get('/')
            ->assertOk()
            ->assertSee('tel:08152070480', false)
            ->assertSee('https://wa.me/2348152070480', false)
            ->assertSee(route('privacy'), false)
            ->assertSee(route('terms'), false)
            // The design language is for builders, not the public footer.
            ->assertDontSee('Design language');
    }

    public function test_the_style_guide_is_kept_out_of_search(): void
    {
        $this->get('/styleguide')->assertOk()->assertSee('noindex', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /styleguide');
    }
}
