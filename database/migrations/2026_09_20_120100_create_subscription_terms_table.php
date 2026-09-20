<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger. One row per block of days she bought.
     *
     * NEVER EDITED. A term is a historical fact -- she paid this much, on this
     * day, for these days -- and the subscription's timestamps are derived
     * from the terms rather than the other way round. Changing the price
     * later must not move a term somebody already bought, which is exactly
     * what editing rows would do.
     *
     * `payment_id` IS UNIQUE, and that is the whole idempotency story. A
     * webhook replayed by Flutterwave, a customer refreshing the return page,
     * and the two racing each other all collapse onto one term. It is the
     * direct analogue of payments.provider_reference and reviews.order_id: the
     * constraint is the rule, so a second arrival is a violation rather than a
     * second month.
     */
    public function up(): void
    {
        Schema::create('subscription_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            /*
             * Nullable so an admin can grant days without a charge -- a
             * goodwill month, a founding tailor. MySQL permits many NULLs in a
             * unique index, which is exactly the behaviour wanted: one term
             * per payment, any number of terms with no payment at all.
             */
            $table->foreignId('payment_id')->nullable()->unique()
                ->constrained()->nullOnDelete();

            $table->enum('plan', ['monthly', 'yearly']);

            /*
             * Snapshotted, both of them. The length and the price are settings
             * an admin is expected to revise, and a term already sold must not
             * move when they do. Same reasoning as an order snapshotting its
             * step labels.
             */
            $table->unsignedSmallInteger('days');
            $table->decimal('amount', 14, 2)->default(0);

            /*
             * Terms STACK. A yearly bought while a monthly is still running
             * starts when that one ends, not today -- so upgrading costs her
             * nothing and there is no proration to get wrong.
             */
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // Who granted it, when there was no payment.
            $table->foreignId('granted_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            // created_at only. A ledger row is never updated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_terms');
    }
};
