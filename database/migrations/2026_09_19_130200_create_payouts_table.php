<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money going out, to the tailor.
     *
     * NO COMMISSION COLUMN, unlike BizyFarmers. This platform takes nothing
     * from an order: the subscription is the revenue, and the Flutterwave
     * charge is absorbed rather than deducted. `gross` and `net` differ only
     * when a refund has already taken something back.
     *
     * One payout per order -- the unique key on order_id means a double
     * release is a constraint violation rather than a second transfer.
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('gross_amount', 14, 2);
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);

            $table->enum('provider', ['flutterwave'])->nullable();
            $table->string('provider_transfer_reference')->nullable();

            /*
             * `pending` while the tailor has no bank details on file, which is
             * a real state and not an error -- releasing escrow must not fail
             * because somebody has not finished their profile. The money is
             * owed either way, and the record of owing it is this row.
             */
            $table->enum('status', ['pending', 'released', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tailor_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
