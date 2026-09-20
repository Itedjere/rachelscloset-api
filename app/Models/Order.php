<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/*
 * `status` and every *_at column are deliberately absent from Fillable, so no
 * request body can move an order through its states. Transitions are our own
 * code and use forceFill, which makes each one visible at the call site.
 */
#[Fillable([
    'customer_id', 'tailor_id', 'garment_type_id', 'description',
    'amount', 'deposit_amount', 'escrow', 'due_date',
])]
class Order extends Model
{
    use HasFactory;

    public const PENDING_PAYMENT = 'pending_payment';

    public const IN_PROGRESS = 'in_progress';

    /** Finished and waiting. The state the brief was missing. */
    public const READY = 'ready';

    public const COLLECTED = 'collected';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const DISPUTED = 'disputed';

    /** @var array<string, string> */
    protected $attributes = [
        'status' => self::PENDING_PAYMENT,

        /*
         * Counts are zero, never unknown. These have schema defaults too, but
         * a schema default is invisible on a model that was just created --
         * the row has it, the object does not -- so reading one back before a
         * refresh yields null and writing that null straight back fails
         * against a NOT NULL column. Same reasoning as status above.
         */
        'steps_total' => 0,
        'steps_completed' => 0,
        'steps_with_photo' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'escrow' => 'boolean',
            'due_date' => 'date',
            'collection_deadline' => 'date',
            'ready_at' => 'datetime',
            'collected_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->reference ??= static::uniqueReference();
        });
    }

    /**
     * Something a customer can read down a phone line.
     *
     * No 0/O or 1/I, because this gets spoken and misheard. Not the id,
     * because an auto-increment in a URL tells the world how many orders the
     * platform has taken.
     */
    public static function uniqueReference(): string
    {
        do {
            $code = 'RC-'.Str::upper(Str::random(6));
            $code = str_replace(['0', 'O', '1', 'I'], ['2', 'Q', '9', 'J'], $code);
        } while (static::where('reference', $code)->exists());

        return $code;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function garmentType(): BelongsTo
    {
        return $this->belongsTo(GarmentType::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OrderStep::class)->orderBy('position');
    }

    /**
     * Every photograph on the order, without going through the steps.
     *
     * This is what the denormalised order_id on order_step_photos buys: the
     * proof ratio, and Section 13's review gate, are one indexed query rather
     * than a join per decision.
     */
    public function stepPhotos(): HasMany
    {
        return $this->hasMany(OrderStepPhoto::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    /** At most two: one each way. The unique index enforces it. */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** What has actually arrived, successful payments only. */
    public function paidTotal(): string
    {
        return (string) $this->payments()->where('status', Payment::SUCCESSFUL)->sum('amount');
    }

    /**
     * What has to be paid before work starts.
     *
     * The deposit if there is one, otherwise the whole amount. A deposit of
     * zero means she is paying on collection, and work starts on trust.
     */
    public function amountDueUpFront(): string
    {
        return bccomp((string) $this->deposit_amount, '0', 2) === 1
            ? (string) $this->deposit_amount
            : (string) $this->amount;
    }

    public function isPaidUpFront(): bool
    {
        return bccomp($this->paidTotal(), $this->amountDueUpFront(), 2) >= 0;
    }

    /**
     * Whether the tailor may release escrow herself yet.
     *
     * Computed from `collected_at`, never scheduled. If the sweep command
     * stops running, she taps a button; the money is never stuck waiting on a
     * cron, which is the same reasoning that makes a suspension lapse on use.
     */
    public function escrowReleaseDue(): bool
    {
        if (! $this->escrow || $this->collected_at === null) {
            return false;
        }

        $days = (int) PlatformSetting::get(PlatformSetting::ESCROW_HOLD_DAYS, 3);

        return $this->collected_at->addDays($days)->isPast();
    }

    public function isEscrow(): bool
    {
        return $this->escrow;
    }

    /** Whoever is not the person asking. */
    public function counterpartTo(User $user): ?User
    {
        return $user->id === $this->customer_id ? $this->tailor : $this->customer;
    }

    public function involves(User $user): bool
    {
        return in_array($user->id, [$this->customer_id, $this->tailor_id], true);
    }

    public function scopeInvolving($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('customer_id', $user->id)->orWhere('tailor_id', $user->id);
        });
    }
}
