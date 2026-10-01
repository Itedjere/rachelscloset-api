<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Numbers an admin can change without a deploy.
 *
 * Keys are declared here as constants rather than typed as strings at the call
 * site, so a rename is a compiler-visible change instead of a setting that
 * silently starts returning its default.
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    /** Seeded, then edited. There is no meaningful "created". */
    public const CREATED_AT = null;

    // What a tailor pays to be listed. Naira.
    public const SUBSCRIPTION_PRICE_MONTHLY = 'subscription_price_monthly';

    public const SUBSCRIPTION_PRICE_YEARLY = 'subscription_price_yearly';

    /** How many days each term buys. Kept adjustable so a promotion is a setting, not a deploy. */
    public const SUBSCRIPTION_MONTHLY_DAYS = 'subscription_monthly_days';

    public const SUBSCRIPTION_YEARLY_DAYS = 'subscription_yearly_days';

    /**
     * Days a lapsed tailor stays listed after her term ends.
     *
     * Exists for one concrete reason: a Nigerian bank transfer that settles a
     * day late must not drop her out of the directory.
     */
    public const SUBSCRIPTION_GRACE_DAYS = 'subscription_grace_days';

    /**
     * How much of a finished order must carry photo proof before a four or
     * five star review publishes without a human reading it first.
     */
    public const REVIEW_PROOF_THRESHOLD = 'review_proof_threshold';

    /** Below this many completed steps, the proof ratio is not trusted either way. */
    public const REVIEW_PROOF_MIN_STEPS = 'review_proof_min_steps';

    /**
     * How long a finished garment waits before collection is overdue.
     *
     * Starts when the tailor marks an order ready. It is what turns "the
     * customer never came back" from a problem she absorbs into one the
     * platform can send reminders about and, eventually, record.
     */
    public const COLLECTION_DEADLINE_DAYS = 'collection_deadline_days';

    /**
     * How long escrow is held after collection before the tailor may release
     * it herself.
     *
     * A window for the customer to say something is wrong. She can also
     * confirm immediately, which releases at once -- the wait is the fallback
     * for silence, not the normal path.
     *
     * Deliberately computed from `collected_at` rather than scheduled. A dead
     * cron then means a tailor taps a button instead of money being stuck,
     * which is the same reasoning as suspensions lapsing on use.
     */
    public const ESCROW_HOLD_DAYS = 'escrow_hold_days';

    /**
     * How many photographs a customer may put on one finished order.
     *
     * Storage, not taste: shared hosting has a real quota and a gallery is
     * the one place on this platform where pictures accumulate without an
     * order to bound them. Five is enough to show a garment from every angle
     * that matters. An admin can move it -- these are operational judgements,
     * not constants.
     */
    public const PORTFOLIO_MAX_PER_ORDER = 'portfolio_max_per_order';

    /**
     * How many a tailor may upload to her own profile.
     *
     * Separate from the per-order cap so a tailor who has never taken an
     * order still has a gallery worth visiting -- which is the whole point
     * of a directory entry, and of the QR card that points at it.
     */
    public const PORTFOLIO_MAX_OWN = 'portfolio_max_own';

    /**
     * How far a tailor with no completed orders is lifted in the directory.
     *
     * Without it a new tailor is last forever and can never earn the reviews
     * that would move her -- a closed shop, and a subscription worth nothing
     * to the people most likely to buy one.
     *
     * The size matters more than it looks. It has to clear a badly-rated
     * tailor and stay UNDER what a single genuine review earns, or an empty
     * profile outranks somebody a real customer actually praised. Against
     * the prior (weight 5 at 3.5) a first five-star review is worth 0.25, so
     * anything at or above that inverts the order. Checked by test.
     */
    public const DIRECTORY_NEWCOMER_BONUS = 'directory_newcomer_bonus';

    /**
     * Whether a tailor must be subscribed to appear in the directory.
     *
     * The listing IS the thing she buys -- it is the whole revenue model, and
     * §3 says a lapse hides her from the directory and nothing else.
     *
     * It is a setting rather than a constant because of the cold start: on
     * launch day a directory that requires payment is empty, and tailors do
     * not pay to join an empty platform. Turning this off runs an
     * introductory period where everyone is listed, without a deploy and
     * without anything else about subscriptions changing.
     */
    public const DIRECTORY_REQUIRES_SUBSCRIPTION = 'directory_requires_subscription';

    /**
     * How long to wait for a customer to confirm a parcel arrived before
     * paying the tailor anyway.
     *
     * The escrow clock runs from the customer confirming receipt, because
     * `collected_at` is the tailor's action and for a posted garment that is
     * the day she went to the post office. This is the backstop: a customer
     * who never confirms must not be able to strand a tailor's money for
     * ever, so after this many days from dispatch it releases regardless.
     *
     * Long, deliberately. It only bites when somebody has gone quiet, and
     * being slow to pay a tailor is a smaller harm than paying her for a
     * garment that never arrived.
     */
    public const ESCROW_RECEIPT_BACKSTOP_DAYS = 'escrow_receipt_backstop_days';

    /** How long a suspension runs when an admin does not say otherwise. */
    public const DEFAULT_SUSPENSION_DAYS = 'default_suspension_days';

    /**
     * The number a locked-out person rings.
     *
     * The ONLY way back in after a forgotten PIN starts with a phone call to
     * Rachel's Closet -- nothing here sends an SMS or an email, and a reset is
     * admin-issued on purpose -- so this number is the whole of the "forgot
     * PIN" flow as far as she can see. A setting rather than a constant
     * because it is a real phone in somebody's hand: a changed SIM must not
     * need a deploy, or the platform is advertising a dead line.
     */
    public const SUPPORT_PHONE = 'support_phone';

    /**
     * How long a notification is kept.
     *
     * Two windows, because read and unread mean different things. Something
     * read is done with -- a fortnight is a generous grace period for going
     * back to it. Something unread is a message that never landed, so it gets a
     * month before being given up on.
     *
     * Settings rather than constants: the right number is an operational
     * judgement about a real disk quota, and finding out it is wrong should not
     * need a deploy.
     */
    public const NOTIFICATION_READ_RETENTION_DAYS = 'notification_read_retention_days';

    public const NOTIFICATION_UNREAD_RETENTION_DAYS = 'notification_unread_retention_days';

    /**
     * When the prune last ran.
     *
     * Written by the command itself so a cron that has quietly stopped is
     * visible on the admin dashboard, rather than being noticed when the
     * shared host runs out of disk. Nothing reads it to make a decision.
     */
    public const NOTIFICATIONS_PRUNED_AT = 'notifications_pruned_at';

    /**
     * When the other two scheduled commands last finished.
     *
     * Same purpose as the one above, and written for the same reason: none of
     * the three is load-bearing, which is exactly what makes a stopped cron
     * invisible. A heartbeat going stale on the admin dashboard is how it
     * becomes visible before somebody notices their money is late or their
     * reminder never came.
     */
    public const PAYOUTS_RELEASED_AT = 'payouts_released_at';

    public const SUBSCRIPTIONS_REMINDED_AT = 'subscriptions_reminded_at';

    public const COLLECTION_REMINDED_AT = 'collection_reminded_at';

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
