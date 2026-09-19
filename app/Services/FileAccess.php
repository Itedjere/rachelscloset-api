<?php

namespace App\Services;

use App\Models\OrderStep;
use App\Models\ProductionStep;
use App\Models\User;

/**
 * Decides who may open an uploaded file.
 *
 * A prefix allowlist on its own is only obscurity: any signed-in account could
 * fetch any file whose path it knew. So each prefix is resolved back to the
 * record that owns it, and access is granted to the people actually involved.
 * A path belonging to no record is refused, which is what makes a guessed path
 * worthless.
 *
 * Step photos and measurement books have no owning record yet; they arrive with
 * Sections 10 and 11. Both are listed now and refuse, and the section that
 * creates the records replaces its resolver. The default is refusal in both
 * directions: an unlisted prefix is refused, and a listed one with no resolver
 * is refused too. A section that forgets to wire its resolver gets a dead 404,
 * not an open door.
 *
 * Two deliberate breaks from BizyFarmers' version, both about measurements:
 * see ADMIN_RESTRICTED_PREFIXES below.
 */
class FileAccess
{
    /** Directories the platform actually writes to. Anything else is refused. */
    public const AVATARS = 'avatars';

    public const STEP_VOICE_NOTES = 'step-voice-notes';

    public const STEP_PHOTOS = 'step-photos';

    public const MEASUREMENTS = 'measurements';

    private const KNOWN_PREFIXES = [
        self::AVATARS,
        self::STEP_VOICE_NOTES,
        self::STEP_PHOTOS,
        self::MEASUREMENTS,
    ];

    /**
     * Where being an admin is not enough.
     *
     * BizyFarmers short-circuits the whole method on `isAdmin()`, because there
     * every upload is dispute material an admin has to be able to read from
     * both sides. That is the wrong default here.
     *
     * A measurement is a photograph of somebody's body, taken in a shop, by
     * somebody she trusted. It is the most sensitive thing this platform holds,
     * and "an admin can look at any of them" is not a property we want to be
     * true by accident. Section 11 gives admins a narrow way in -- a dedicated
     * `measurements.view` permission plus a real dispute on an order between
     * that customer and that tailor. Until then the answer is simply no.
     *
     * This list exists in Section 3, before a single measurement record can be
     * created, on purpose. Relaxing a closed rule later is a deliberate act;
     * remembering to close an open one eight sections after the fact is not.
     */
    private const ADMIN_RESTRICTED_PREFIXES = [
        self::MEASUREMENTS,
    ];

    public function allows(?User $user, string $path): bool
    {
        if (! $user) {
            return false;
        }

        $prefix = explode('/', $path)[0] ?? '';

        // The prefix check comes first and applies to everyone: an admin has
        // reason to read uploads, not to reach anywhere on the disk.
        if (! in_array($prefix, self::KNOWN_PREFIXES, true)) {
            return false;
        }

        if ($user->isAdmin() && ! in_array($prefix, self::ADMIN_RESTRICTED_PREFIXES, true)) {
            return true;
        }

        return match ($prefix) {
            /*
             * Profile photographs are visible to any signed-in account by
             * design: they appear in the directory, beside every review and on
             * a business card, so restricting them to people who "know" the
             * person would be a rule with no meaning. Still checked against a
             * real record, so a guessed path finds nothing.
             */
            self::AVATARS => $this->isSomeonesAvatar($path),

            /*
             * An admin records these and every signed-in account may play
             * them: they are the library, and being able to listen rather
             * than read is the point of the whole design. A tailor has to
             * hear a step to tick it off, and a customer has to hear what
             * she is being told has happened.
             *
             * Resolved against a real record, so a guessed path finds
             * nothing.
             *
             * Two places count: a step's current recording, AND any path an
             * order_step snapshotted. The second is not optional. Replacing a
             * library recording leaves the old file on disk with nothing in
             * production_steps pointing at it -- and every order assembled
             * before the replacement still holds that path. Without the
             * second check, an admin re-recording one step would silence it
             * for every customer part-way through an order, which is the
             * whole reason the old file is kept.
             */
            self::STEP_VOICE_NOTES => $this->isAStepRecording($path),

            // Section 10: the customer and the tailor on the order it proves.
            self::STEP_PHOTOS => false,

            // Section 11: her own, or a tailor with consent. See MeasurementAccess.
            self::MEASUREMENTS => false,

            default => false,
        };
    }

    /**
     * A recording that belongs to a step, current or superseded.
     *
     * A superseded path is reachable precisely because an order_step still
     * holds it. That is the point of write-once recordings.
     */
    private function isAStepRecording(string $path): bool
    {
        return ProductionStep::query()->where('voice_note_url', $path)->exists()
            || OrderStep::query()->where('voice_note_url', $path)->exists();
    }

    /** A photograph somebody actually set, rather than a path off the disk. */
    private function isSomeonesAvatar(string $path): bool
    {
        return User::query()->where('avatar_url', $path)->exists();
    }
}
