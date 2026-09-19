<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money coming in.
     *
     * Carries `purpose` and a nullable `subscription_id` from the start, even
     * though subscriptions are Section 14. The alternative is an ALTER on a
     * table that by then holds real money, and the shape is already known --
     * the plan specifies it. `order_id` is nullable for the same reason: a
     * subscription payment belongs to no order.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->enum('purpose', ['order', 'subscription'])->default('order');

            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            // Constrained in Section 14, when the table it points at exists.
            $table->unsignedBigInteger('subscription_id')->nullable();

            // Who is paying. Kept even though it is derivable from the order,
            // because a subscription payment has no order to derive it from.
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();

            /*
             * Flutterwave only. An enum with one value looks odd, but it makes
             * adding a second provider a migration rather than a rewrite, and
             * it documents that the column is not free text.
             */
            $table->enum('provider', ['flutterwave'])->default('flutterwave');

            /*
             * UNIQUE, and this is the whole idempotency story. A replayed
             * webhook, a browser returning twice, and the two racing each
             * other all collapse to one row. Everything downstream -- marking
             * an order paid, minting a subscription term -- hangs off the
             * insert succeeding.
             */
            $table->string('provider_reference')->unique();

            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'successful', 'failed'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['payer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
