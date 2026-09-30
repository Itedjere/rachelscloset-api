<?php

namespace App\Support;

use App\Models\User;

/**
 * Which kinds of alert a person can turn off, and what falls under each.
 *
 * Grouped rather than listed one by one on purpose. There will be twenty-odd
 * notification types by the end, and a settings page with twenty-odd switches
 * is one nobody reads -- least of all somebody who reads poorly. Four groups is
 * a decision a person can actually make.
 *
 * The mapping lives here rather than as a method on each notification, so a new
 * type is one line in one file instead of a field on a class nobody remembers
 * to fill in.
 *
 * These govern PUSH, not email. Nothing in this project requires an address.
 */
class NotificationCategories
{
    public const PROGRESS = 'progress';

    public const ORDERS = 'orders';

    public const MONEY = 'money';

    public const MEASUREMENTS = 'measurements';

    /**
     * Things about the account itself, which cannot be switched off.
     *
     * Being told you have been suspended is not marketing. Somebody who cannot
     * sign in and was never told why walks to the shop, or decides the whole
     * platform is broken.
     */
    public const ACCOUNT = 'account';

    /**
     * Every group that can be switched off, whoever is asking.
     *
     * This is what is STORED and validated, and it is deliberately wider than
     * what any one person is shown (see OFFERED). Keeping the stored shape the
     * same for everybody means a change to who sees which switch can never
     * reset a choice somebody already made.
     */
    public const OPTIONAL = [self::PROGRESS, self::ORDERS, self::MONEY, self::MEASUREMENTS];

    /**
     * The switches each role is shown, in order, in words about her own side.
     *
     * Per role because the two sides receive different alerts under the same
     * group. One shared list meant a tailor was offered "Work on your clothes"
     * -- which only ever goes to the customer -- and a customer was told about
     * "when your listing is due", which is a tailor's subscription. A switch
     * that governs nothing is worse than no switch: she turns it off, nothing
     * changes, and she stops trusting the rest of the page.
     *
     * A group is listed for a role only if something actually sends her one of
     * its types. MEASUREMENTS is therefore offered to nobody yet: the three
     * measurement_access_* types are mapped below but nothing sends them.
     * Add it back here in the same change that starts sending one.
     *
     * Admins are offered nothing. Every alert an admin receives is an ACCOUNT
     * one, which cannot be switched off.
     */
    private const OFFERED = [
        User::ROLE_CUSTOMER => [
            self::PROGRESS => [
                'label' => 'Work on your clothes',
                'hint' => 'Each stage as it is finished, and when it is ready to collect',
            ],
            self::ORDERS => [
                'label' => 'Collecting',
                'hint' => 'Reminders to collect, and what your tailor said about you',
            ],
            self::MONEY => [
                'label' => 'Money',
                'hint' => 'Money paid back to you, and what was decided about a complaint',
            ],
        ],
        User::ROLE_TAILOR => [
            self::ORDERS => [
                'label' => 'Orders',
                'hint' => 'When a customer pays, when clothes are not collected, and new reviews',
            ],
            self::MONEY => [
                'label' => 'Money',
                'hint' => 'Money sent to your bank, complaints about an order, and when your listing is due',
            ],
        ],
    ];

    /**
     * Every notification type, and the group it belongs to.
     *
     * Populated ahead of the sections that will send these, because the
     * settings page is otherwise a set of switches governing nothing. Each
     * later section confirms its own type string here as it lands -- a name
     * that turns out different is one line, and an unmapped type still reaches
     * people (see `for`), so a mistake here goes loud rather than silent.
     */
    private const MAP = [
        // Section 9-10. The step tracker is the product; this is its alert.
        'step_completed' => self::PROGRESS,
        'order_ready' => self::PROGRESS,

        // Sections 5, 9, 12.
        'order_placed' => self::ORDERS,
        'order_paid' => self::ORDERS,
        'collection_reminder' => self::ORDERS,
        'order_completed' => self::ORDERS,
        'review_received' => self::ORDERS,

        // Sections 5, 12, 14.
        'payout_released' => self::MONEY,
        'order_refunded' => self::MONEY,
        // A dispute is about somebody's money and her garment; it belongs
        // with the money rather than with progress updates.
        'order_disputed' => self::MONEY,
        'dispute_resolved' => self::MONEY,
        'subscription_expiring' => self::MONEY,
        'subscription_lapsed' => self::MONEY,

        // Section 11.
        'measurement_access_requested' => self::MEASUREMENTS,
        'measurement_access_granted' => self::MEASUREMENTS,
        'measurement_access_revoked' => self::MEASUREMENTS,

        // Section 6. Not switchable, listed for the sake of being complete.
        'account_suspended' => self::ACCOUNT,
        'account_reinstated' => self::ACCOUNT,
        'pin_reset' => self::ACCOUNT,
    ];

    /**
     * The group a type belongs to.
     *
     * An unmapped type falls under ACCOUNT, which cannot be switched off -- a
     * new notification somebody forgot to categorise should reach people, not
     * go quietly missing.
     */
    public static function for(string $type): string
    {
        return self::MAP[$type] ?? self::ACCOUNT;
    }

    public static function isOptional(string $category): bool
    {
        return in_array($category, self::OPTIONAL, true);
    }

    /**
     * The switches to show somebody with this role, each with its key.
     *
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function offeredTo(string $role): array
    {
        return array_map(
            fn (string $key, array $group) => ['key' => $key] + $group,
            array_keys(self::OFFERED[$role] ?? []),
            self::OFFERED[$role] ?? [],
        );
    }

    /** Everything on, which is what an account starts as. */
    public static function defaults(): array
    {
        return array_fill_keys(self::OPTIONAL, true);
    }
}
