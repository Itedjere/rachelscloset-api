<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\FileAccess;
use App\Support\UploadLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Editing your own account.
 *
 * The phone number is absent on purpose. It is the username, it is unique, and
 * moving it moves what somebody signs in with -- so it needs the claim-code
 * flow from Section 11 to prove the new number is really hers. A writable field
 * here would be a way to lock yourself out of your own account with one typo,
 * and for somebody with no email address there would be no way back in.
 */
class ProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            /*
             * Still optional, still rare. Unique against everybody else so the
             * few accounts that do have one can sign in with it -- the same
             * reason `findByIdentifier` treats it as an identifier at all.
             */
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        /*
         * Absent and blank are different answers.
         *
         * Blank means "I have no address, clear it" and is stored as null --
         * never an empty string, or the second account to clear one would
         * collide on the unique index. A request that omits the key entirely is
         * not talking about the address at all and must leave it alone, which
         * matters because a client sending a partial update should not be able
         * to silently strip the one recovery route an account has.
         */
        if ($request->has('email')) {
            $validated['email'] = $validated['email'] ?: null;
        } else {
            unset($validated['email']);
        }

        $user->update($validated);

        return UserResource::make($user->fresh()->loadMissing('tailorProfile'));
    }

    public function updateAvatar(Request $request): UserResource
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                // 2MB is plenty for a photo displayed at forty pixels, but the
                // ini value still binds -- the rule must never promise more
                // than the server will actually accept.
                'max:'.min(2048, UploadLimits::maxKilobytes()),
            ],
        ], [
            'avatar.mimetypes' => 'Upload a JPEG, PNG or WebP image.',
            'avatar.max' => 'That photo is too large even after shrinking. Try a different one.',
        ]);

        $user = $request->user();
        $previous = $user->avatar_url;

        $path = $request->file('avatar')->store(FileAccess::AVATARS, 'local');

        $user->update(['avatar_url' => $path]);

        /*
         * Deleted, unlike a step's voice note.
         *
         * An avatar is decoration and nothing snapshots it, so the old file is
         * only cost. Section 7's voice notes are the opposite -- an order
         * snapshots the path it was told, so replacing one must store a new
         * path and leave the old file alone. Different rules, deliberately.
         */
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return UserResource::make($user->fresh()->loadMissing('tailorProfile'));
    }

    /** Back to initials. */
    public function deleteAvatar(Request $request): UserResource
    {
        $user = $request->user();
        $previous = $user->avatar_url;

        $user->update(['avatar_url' => null]);

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return UserResource::make($user->fresh()->loadMissing('tailorProfile'));
    }
}
