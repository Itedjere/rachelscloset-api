<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    /**
     * Starting values. Every one of these is an operational judgement the admin
     * is expected to revise, so none of them is treated as a constant anywhere
     * in the code.
     */
    public const DEFAULTS = [
        PlatformSetting::SUBSCRIPTION_PRICE_MONTHLY => '2000',
        PlatformSetting::SUBSCRIPTION_PRICE_YEARLY => '20000',
        PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS => '30',
        PlatformSetting::SUBSCRIPTION_YEARLY_DAYS => '365',
        PlatformSetting::SUBSCRIPTION_GRACE_DAYS => '7',
        PlatformSetting::REVIEW_PROOF_THRESHOLD => '80',
        PlatformSetting::REVIEW_PROOF_MIN_STEPS => '3',
        PlatformSetting::DEFAULT_SUSPENSION_DAYS => '14',
        PlatformSetting::COLLECTION_DEADLINE_DAYS => '14',
        PlatformSetting::ESCROW_HOLD_DAYS => '3',
        PlatformSetting::PORTFOLIO_MAX_PER_ORDER => '5',
        PlatformSetting::PORTFOLIO_MAX_OWN => '5',
        PlatformSetting::DIRECTORY_NEWCOMER_BONUS => '0.2',
        PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION => '1',
        PlatformSetting::NOTIFICATION_READ_RETENTION_DAYS => '14',
        PlatformSetting::NOTIFICATION_UNREAD_RETENTION_DAYS => '30',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            // Seeded, never overwritten — re-running must not undo an admin.
            PlatformSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
