<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * An outstanding invitation to take over a profile.
 *
 * Nothing here is mass-assignable: every field is a credential or a fact
 * about one, and all of them are set by issue() below.
 */
class ClaimToken extends Model
{
    use HasFactory;

    public const CLAIM = 'claim';

    public const PIN_RESET = 'pin_reset';

    /**
     * How long an invitation lives.
     *
     * Short, because the two people are together when it is issued and the
     * customer scans it within the minute. Long enough that "I will do it
     * when I get home" still works.
     */
    public const LIFETIME_HOURS = 48;

    /**
     * A PIN reset lives far less long, on purpose.
     *
     * A claim code opens an empty profile nobody has used. A reset code opens
     * an account with orders, money and measurements already in it, and it is
     * issued while an admin is on the phone with the person who needs it --
     * so there is no "I will do it when I get home" to accommodate. Four
     * hours is generous for a call that is happening now.
     */
    public const RESET_LIFETIME_HOURS = 4;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The tailor who invited her. Shown on the claim page so she knows why. */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Mint one, and hand back the plaintext exactly once.
     *
     * Any outstanding invitation for the same person and purpose is expired
     * first: two live codes for one account is two chances to guess, and the
     * tailor re-issuing means she has lost the first one anyway.
     *
     * @return array{token: ClaimToken, link_token: string, code: string}
     */
    public static function issue(User $user, ?User $issuedBy = null, string $purpose = self::CLAIM): array
    {
        self::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['expires_at' => now()->subSecond()]);

        $linkToken = Str::random(48);

        // Six digits, spoken down a phone line. Padded, so "004821" is not
        // read back as "4821" and refused.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $token = self::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $linkToken),
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addHours(
                $purpose === self::PIN_RESET ? self::RESET_LIFETIME_HOURS : self::LIFETIME_HOURS,
            ),
            'issued_by' => $issuedBy?->id,
        ]);

        return ['token' => $token, 'link_token' => $linkToken, 'code' => $code];
    }

    /**
     * The link token is looked up, not compared.
     *
     * A 48-character random string has no guessing risk worth a slow hash, so
     * sha256 is right here -- it keeps the column indexable, which is what
     * makes this one query instead of a scan over every outstanding invite.
     */
    public static function findByLinkToken(string $linkToken): ?self
    {
        return self::query()
            ->where('token_hash', hash('sha256', $linkToken))
            ->first();
    }

    public function codeMatches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function markUsed(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }
}
