<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A browser that has agreed to receive alerts.
     *
     * One row per device rather than per person: a tailor signed in on the shop
     * phone and at home should be told on both, and each browser issues its own
     * endpoint and its own pair of keys.
     *
     * The endpoint is unique across the whole table, not per user. It identifies
     * the browser, and a phone handed to a second account must stop receiving
     * the first account's alerts rather than receiving both.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Long: Chrome's FCM endpoints run past 200 characters, and a
            // varchar(255) unique index is already at MySQL's comfortable limit.
            $table->string('endpoint', 500)->unique();
            $table->string('public_key');
            $table->string('auth_token');

            // Only so somebody can tell their own devices apart when unhooking
            // one. Not identification -- a user agent is a poor guide at best.
            $table->string('device_label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
