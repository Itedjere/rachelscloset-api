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

    /** How long a suspension runs when an admin does not say otherwise. */
    public const DEFAULT_SUSPENSION_DAYS = 'default_suspension_days';

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

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
