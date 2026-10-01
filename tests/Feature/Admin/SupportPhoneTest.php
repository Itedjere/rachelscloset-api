<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The number a locked-out person rings -- the whole of "forgot PIN" as she
 * sees it -- kept in a setting an admin can change without a deploy.
 */
class SupportPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_on_the_admin_settings_screen_as_a_phone(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '08152070480');
        Sanctum::actingAs(User::factory()->admin()->create());

        $row = collect($this->getJson('/api/admin/settings')->assertOk()->json('data'))
            ->firstWhere('key', PlatformSetting::SUPPORT_PHONE);

        $this->assertNotNull($row);
        $this->assertTrue($row['phone']);
        $this->assertSame('08152070480', $row['value']);
    }

    /** Stored the one canonical way, like every other phone on the platform. */
    public function test_an_admin_changes_it_and_it_is_normalised(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/settings', [
            'key' => PlatformSetting::SUPPORT_PHONE,
            'value' => '+234 815 207 0481',
        ])->assertOk()->assertJsonPath('data.value', '08152070481');

        $this->assertSame('08152070481', PlatformSetting::get(PlatformSetting::SUPPORT_PHONE));
    }

    /** A typo is a dead line on the page a locked-out person reads. */
    public function test_something_that_is_not_a_phone_number_is_refused(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '08152070480');
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (['0815207', 'call us', '12345678901'] as $bad) {
            $this->putJson('/api/admin/settings', ['key' => PlatformSetting::SUPPORT_PHONE, 'value' => $bad])
                ->assertJsonValidationErrors('value');
        }

        $this->assertSame('08152070480', PlatformSetting::get(PlatformSetting::SUPPORT_PHONE));
    }

    /** The number settings still take whole numbers only. */
    public function test_the_numeric_settings_still_refuse_text(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/settings', [
            'key' => PlatformSetting::COLLECTION_DEADLINE_DAYS,
            'value' => '08152070480',
        ])->assertJsonValidationErrors('value');
    }

    public function test_only_an_admin_can_change_it(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->putJson('/api/admin/settings', [
            'key' => PlatformSetting::SUPPORT_PHONE,
            'value' => '08031234567',
        ])->assertForbidden();
    }

    /** Public: the only person who needs it is somebody who cannot sign in. */
    public function test_a_signed_out_visitor_can_read_it(): void
    {
        PlatformSetting::set(PlatformSetting::SUPPORT_PHONE, '08152070480');

        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('data.support_phone', '08152070480')
            ->assertJsonPath('data.support_whatsapp', 'https://wa.me/2348152070480');
    }

    /** Unset is null, so the page says "contact us" rather than inventing a number. */
    public function test_unset_is_null_not_empty(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('data.support_phone', null)
            ->assertJsonPath('data.support_whatsapp', null);
    }
}
