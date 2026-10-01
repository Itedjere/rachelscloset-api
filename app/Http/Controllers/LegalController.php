<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use Illuminate\View\View;

/**
 * The privacy notice and the terms.
 *
 * Every number in them -- how many days before a tailor is paid, how long a
 * listing's grace lasts, how long notifications are kept -- is read from
 * platform_settings at request time rather than typed into the page. Those
 * are operational judgements an admin changes from the Settings screen, and
 * terms that said "3 days" after somebody set it to 5 would be the platform
 * contradicting itself in the one document people are meant to rely on.
 *
 * DRAFTS. Written from what the code actually does, and marked as drafts on
 * the page, until a lawyer has read them. See CLAUDE.md.
 */
class LegalController extends Controller
{
    public function privacy(): View
    {
        return view('public.privacy', ['n' => $this->numbers()]);
    }

    public function terms(): View
    {
        return view('public.terms', ['n' => $this->numbers()]);
    }

    /** @return array<string, string> */
    private function numbers(): array
    {
        // Defaults mirror PlatformSettingSeeder, for a database not yet seeded.
        $get = fn (string $key, string $default) => (string) PlatformSetting::get($key, $default);

        return [
            'hold_days' => $get(PlatformSetting::ESCROW_HOLD_DAYS, '3'),
            'backstop_days' => $get(PlatformSetting::ESCROW_RECEIPT_BACKSTOP_DAYS, '21'),
            'collect_days' => $get(PlatformSetting::COLLECTION_DEADLINE_DAYS, '14'),
            'grace_days' => $get(PlatformSetting::SUBSCRIPTION_GRACE_DAYS, '7'),
            'monthly_days' => $get(PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS, '30'),
            'yearly_days' => $get(PlatformSetting::SUBSCRIPTION_YEARLY_DAYS, '365'),
            'proof_threshold' => $get(PlatformSetting::REVIEW_PROOF_THRESHOLD, '80'),
            'read_days' => $get(PlatformSetting::NOTIFICATION_READ_RETENTION_DAYS, '14'),
            'unread_days' => $get(PlatformSetting::NOTIFICATION_UNREAD_RETENTION_DAYS, '30'),
            'support_phone' => $get(PlatformSetting::SUPPORT_PHONE, ''),
        ];
    }
}
