<?php

namespace App\Models;

use App\Rules\NigerianPhone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'avatar_url', 'status', 'suspended_until'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_TAILOR = 'tailor';

    public const ROLE_ADMIN = 'admin';

    /** Roles a member of the public may choose at signup. Admins are seeded or promoted. */
    public const SELF_SIGNUP_ROLES = [self::ROLE_CUSTOMER, self::ROLE_TAILOR];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * `status` also has a database default, but a default in the schema is not
     * visible on a model that was just created -- the row has it, the object in
     * memory does not. That made a freshly registered account read as inactive
     * until it was loaded again, which is exactly the kind of difference
     * between "the database is right" and "the code is right" that shows up
     * somewhere far away from here.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_until' => 'datetime',
        ];
    }

    public function tailorProfile(): HasOne
    {
        return $this->hasOne(TailorProfile::class);
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isTailor(): bool
    {
        return $this->role === self::ROLE_TAILOR;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether a fixed-term suspension has run out.
     *
     * Checked when the account is next used rather than swept by a scheduled
     * job, because cron on shared hosting can quietly stop, and an account left
     * locked out past its own end date would be invisible until somebody rang
     * up to complain.
     */
    public function suspensionHasExpired(): bool
    {
        return $this->status === self::STATUS_SUSPENDED
            && $this->suspended_until !== null
            && $this->suspended_until->isPast();
    }

    /**
     * Whether this account has been claimed by the person it describes.
     *
     * A tailor can create a customer from the shop floor so she is not blocked
     * by paperwork while somebody is standing in front of her. That record has
     * no PIN until the customer claims it, and an unclaimed profile has given
     * consent to nothing — which is what the measurement rules turn on.
     */
    public function isClaimed(): bool
    {
        return $this->password !== null;
    }

    /**
     * What a password reset token is filed under.
     *
     * Laravel keys `password_reset_tokens` on this and the column is that
     * table's primary key, so an account with no address cannot have a token
     * issued at all — which would break recovery for precisely the people this
     * platform is built for.
     *
     * The phone number stands in where there is no address. It is unique, it is
     * required, and it is already what that person signs in with.
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->email ?: (string) $this->phone;
    }

    /**
     * The account somebody means when they type a phone number — or an email
     * address, for the few who have one — into a single box.
     *
     * One place rather than three, because signing in, resetting a PIN and the
     * admin user search must agree on what a given string identifies. If they
     * disagreed, the symptom would be somebody able to sign in but unable to
     * recover the same account.
     *
     * A number is matched on its normalised form, so 0803..., +234803... and a
     * number pasted with spaces in it all find the row that was stored the one
     * canonical way. Anything that is not a Nigerian number is treated as an
     * address, so a typo in an email never accidentally matches a phone.
     */
    public static function findByIdentifier(?string $identifier): ?self
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return null;
        }

        if (($phone = NigerianPhone::normalise($identifier)) !== null) {
            return static::where('phone', $phone)->first();
        }

        return static::where('email', $identifier)->first();
    }
}
