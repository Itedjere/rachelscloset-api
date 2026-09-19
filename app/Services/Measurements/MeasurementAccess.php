<?php

namespace App\Services\Measurements;

use App\Models\MeasurementSet;
use App\Models\Order;
use App\Models\TailorCustomerLink;
use App\Models\User;

/**
 * Who may see somebody's measurements.
 *
 * The most sensitive data this platform holds. A measurement set is a
 * photograph of a person's body written down in a shop by somebody she
 * trusted, and every rule below exists because the obvious shortcut is worse.
 *
 * The ladder, in order:
 *
 *   1. It is her own body                                        -> yes
 *   2. An admin                                                  -> no, see below
 *   3. An unclaimed profile: only the tailor who recorded it     -> maybe
 *   4. A tailor with a LIVE order with her                       -> yes
 *   5. A tailor with a granted link                              -> yes
 *   6. Anyone else                                               -> no
 *
 * The answer to "no" is always 404, never 403. A 403 confirms the record
 * exists, and for this data the existence of a row is itself worth not
 * confirming -- it says a named person has been measured by somebody.
 *
 * FileAccess depends on this class rather than reimplementing it. If the two
 * could drift, a tailor who cannot open the record could still stream the
 * photograph by knowing its path, which is the whole rule defeated by a URL.
 */
class MeasurementAccess
{
    /**
     * Orders that count as a working relationship.
     *
     * A FINISHED ORDER DOES NOT GRANT PERMANENT ACCESS. The alternative --
     * "she was once my customer, so I keep her measurements forever" -- turns
     * one job into an indefinite claim on somebody's body, and it accumulates
     * silently. The repeat customer is meant to grant a link instead, which
     * she can see and take back.
     *
     * `disputed` is included because that is precisely when both sides need
     * to see what was actually recorded.
     */
    private const LIVE_STATUSES = [
        Order::PENDING_PAYMENT,
        Order::IN_PROGRESS,
        Order::READY,
        Order::DISPUTED,
    ];

    public function allows(?User $user, MeasurementSet $set): bool
    {
        if (! $user) {
            return false;
        }

        // 1. Her own body.
        if ($set->customer_id === $user->id) {
            return true;
        }

        /*
         * 2. Admins get nothing here.
         *
         * A deliberate break from BizyFarmers, where isAdmin() short-circuits
         * the whole file rule because there every upload is dispute material.
         * "Any staff member can look at any customer's body measurements" is
         * not a property we want true by accident, and it is exactly the sort
         * of thing that is true by accident.
         *
         * The plan allows a narrow way in: a dedicated `measurements.view`
         * permission AND a real dispute on an order between that customer and
         * that tailor. Neither exists yet -- there is no staff permission
         * system and nothing in any section opens a dispute, though
         * Order::DISPUTED is reserved for it. Until both are built the honest
         * answer is no, and no is the safe direction to be wrong in.
         */
        if ($user->isAdmin()) {
            return false;
        }

        if (! $user->isTailor()) {
            return false;
        }

        /*
         * 3. An unclaimed profile has consented to nothing.
         *
         * A tailor can create a customer from the shop floor, which is what
         * makes this usable at all -- but that person has not agreed to
         * anything yet, so her measurements stay with the one tailor who
         * took them. Cross-tailor history begins the moment a real person
         * claims the profile and consents, and not one step earlier.
         */
        if (! $set->customer->isClaimed()) {
            return $set->recorded_by === $user->id;
        }

        // 4 and 5.
        return $this->hasLiveOrderWith($user, $set->customer_id)
            || $this->hasGrantedLink($user, $set->customer_id);
    }

    /**
     * Whether this person may see that customer's measurements at all.
     *
     * Used by the listing endpoint, where there is no single set to ask
     * about. Deliberately does not consider the "recorded by me" rule: an
     * unclaimed profile's list is filtered per row instead, because two
     * tailors may each have measured the same walk-in.
     */
    public function canSeeAnyOf(?User $user, User $customer): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->id === $customer->id) {
            return true;
        }

        if (! $user->isTailor()) {
            return false;
        }

        if (! $customer->isClaimed()) {
            return true; // Filtered per row; she may have recorded some of them.
        }

        return $this->hasLiveOrderWith($user, $customer->id)
            || $this->hasGrantedLink($user, $customer->id);
    }

    /**
     * Revoking does not strip a tailor mid-order.
     *
     * Cloth is already cut. Pulling the measurements out of her hands halfway
     * through does not protect anybody -- the tailor has them on paper and in
     * the garment -- it just ruins the garment. The revoke button says so in
     * as many words, because a button that quietly does less than it claims
     * is worse than one that explains itself.
     */
    private function hasLiveOrderWith(User $tailor, int $customerId): bool
    {
        return Order::query()
            ->where('tailor_id', $tailor->id)
            ->where('customer_id', $customerId)
            ->whereIn('status', self::LIVE_STATUSES)
            ->exists();
    }

    private function hasGrantedLink(User $tailor, int $customerId): bool
    {
        return TailorCustomerLink::query()
            ->where('tailor_id', $tailor->id)
            ->where('customer_id', $customerId)
            ->where('status', TailorCustomerLink::GRANTED)
            ->exists();
    }
}
