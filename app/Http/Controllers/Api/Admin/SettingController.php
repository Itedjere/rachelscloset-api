<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Rules\NigerianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The numbers an admin is expected to revise.
 *
 * Every one of these is an operational judgement rather than a constant --
 * the seeder says so -- and until now the only way to change one was a
 * database client. That is fine for a developer and useless to the person
 * actually running the business.
 *
 * ONLY THE KEYS LISTED HERE ARE WRITABLE. `platform_settings` also holds
 * bookkeeping like `notifications_pruned_at`, which is written by a command
 * and read by nobody to make a decision; exposing the whole table would let
 * an admin edit machinery by accident while looking for a price.
 */
class SettingController extends Controller
{
    /**
     * What may be edited, in the order it makes sense to read.
     *
     * `money` marks the two rows that are naira rather than a count of days
     * or a percentage, so the screen can group the digits as they are typed.
     * Said here rather than guessed from the label in the client: "(naira)"
     * in a string is not a contract.
     *
     * `phone` marks the one row that is not a number at all, so it is
     * validated as a Nigerian phone and its min/max are meaningless.
     *
     * @var array<string, array{label: string, help: string, min: int, max: int, group: string, money?: bool, phone?: bool}>
     */
    private const EDITABLE = [
        PlatformSetting::SUPPORT_PHONE => [
            'label' => 'Phone number for help',
            'help' => 'Shown to anybody who forgot her PIN: she rings this, and an admin '
                .'gives her six numbers from the People screen. Check it rings.',
            'phone' => true,
            'min' => 0, 'max' => 0, 'group' => 'Contact',
        ],
        PlatformSetting::PORTFOLIO_MAX_OWN => [
            'label' => 'Photographs a tailor may add to her own profile',
            'help' => 'So a new tailor has a gallery before her first order.',
            'min' => 1, 'max' => 20, 'group' => 'Gallery',
        ],
        PlatformSetting::PORTFOLIO_MAX_PER_ORDER => [
            'label' => 'Photographs a customer may add to one order',
            'help' => 'Pictures of her wearing the finished garment. These carry the gallery.',
            'min' => 1, 'max' => 20, 'group' => 'Gallery',
        ],
        PlatformSetting::SUBSCRIPTION_PRICE_MONTHLY => [
            'label' => 'Monthly listing price (naira)',
            'help' => 'What 30 days in the Fashion House costs.',
            'money' => true,
            'min' => 0, 'max' => 1000000, 'group' => 'Listings',
        ],
        PlatformSetting::SUBSCRIPTION_PRICE_YEARLY => [
            'label' => 'Yearly listing price (naira)',
            'help' => 'Changing a price never moves a term somebody already bought.',
            'money' => true,
            'min' => 0, 'max' => 10000000, 'group' => 'Listings',
        ],
        PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS => [
            'label' => 'Days in a monthly term',
            'help' => 'Terms stack, so renewing early loses nothing.',
            'min' => 1, 'max' => 400, 'group' => 'Listings',
        ],
        PlatformSetting::SUBSCRIPTION_YEARLY_DAYS => [
            'label' => 'Days in a yearly term',
            'help' => 'Terms stack, so renewing early loses nothing.',
            'min' => 1, 'max' => 2000, 'group' => 'Listings',
        ],
        PlatformSetting::SUBSCRIPTION_GRACE_DAYS => [
            'label' => 'Grace days after a term ends',
            'help' => 'She stays listed. A bank transfer settling late must not delist her.',
            'min' => 0, 'max' => 60, 'group' => 'Listings',
        ],
        PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION => [
            'label' => 'Tailors must be subscribed to be listed (1 or 0)',
            'help' => 'Set to 0 for an introductory period where everyone is listed. '
                .'Nothing else about subscriptions changes.',
            'min' => 0, 'max' => 1, 'group' => 'Listings',
        ],

        PlatformSetting::REVIEW_PROOF_THRESHOLD => [
            'label' => 'Photographed work needed to publish 4 and 5 star reviews (%)',
            'help' => 'Below this, a high rating waits to be checked. Complaints always publish.',
            'min' => 0, 'max' => 100, 'group' => 'Reviews',
        ],
        PlatformSetting::REVIEW_PROOF_MIN_STEPS => [
            'label' => 'Stages an order needs before that check applies',
            'help' => 'Short orders are not held: two stages with one photograph means nothing.',
            'min' => 1, 'max' => 20, 'group' => 'Reviews',
        ],
        PlatformSetting::COLLECTION_DEADLINE_DAYS => [
            'label' => 'Days to collect a finished garment',
            'help' => 'Starts when the tailor marks it ready.',
            'min' => 1, 'max' => 90, 'group' => 'Orders',
        ],
        PlatformSetting::ESCROW_HOLD_DAYS => [
            'label' => 'Days held after collection before a tailor is paid',
            'help' => 'A window to raise a problem. The customer can also confirm at once.',
            'min' => 0, 'max' => 30, 'group' => 'Orders',
        ],
        PlatformSetting::DEFAULT_SUSPENSION_DAYS => [
            'label' => 'Default length of a suspension',
            'help' => 'Used when an admin does not say otherwise.',
            'min' => 1, 'max' => 365, 'group' => 'Accounts',
        ],
        PlatformSetting::NOTIFICATION_READ_RETENTION_DAYS => [
            'label' => 'Days to keep read notifications',
            'help' => 'This table grows fastest of any: one nine-stage garment is nine rows.',
            'min' => 1, 'max' => 365, 'group' => 'Housekeeping',
        ],
        PlatformSetting::NOTIFICATION_UNREAD_RETENTION_DAYS => [
            'label' => 'Days to keep unread notifications',
            'help' => 'Longer than read ones: nobody should lose something never seen.',
            'min' => 1, 'max' => 365, 'group' => 'Housekeeping',
        ],
    ];

    public function index(): JsonResponse
    {
        $rows = collect(self::EDITABLE)->map(fn (array $meta, string $key) => [
            'key' => $key,
            'value' => (string) PlatformSetting::get($key, ''),
            'money' => false,
            'phone' => false,
            ...$meta,
        ])->values();

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', Rule::in(array_keys(self::EDITABLE))],
            'value' => ['required'],
        ]);

        $meta = self::EDITABLE[$validated['key']];

        if ($meta['phone'] ?? false) {
            // A typo here is a dead line on the page a locked-out person
            // reads, so it must at least be a real Nigerian number -- stored
            // in the one canonical form, like every other phone here.
            $request->validate(['value' => ['string', new NigerianPhone]], [], ['value' => 'phone number']);
            $value = NigerianPhone::normalise($request->string('value')->value());
        } else {
            // Bounds per key, because "0 days to collect" and "100000
            // photographs" are both ways to break the platform from a
            // settings screen.
            $request->validate([
                'value' => ['integer', 'min:'.$meta['min'], 'max:'.$meta['max']],
            ]);
            $value = (string) (int) $validated['value'];
        }

        PlatformSetting::set($validated['key'], $value);

        return response()->json(['data' => [
            'key' => $validated['key'],
            'value' => $value,
        ]]);
    }
}
