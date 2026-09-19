<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every webhook the provider sent, and what was done with it.
     *
     * Not needed for idempotency -- `payments.provider_reference` already does
     * that. It exists for one support ticket that is guaranteed to arrive:
     * "Flutterwave debited me and the app says unpaid". Bank transfer and USSD
     * settle late, so that gap is real, and without a log of what arrived and
     * when, it is unanswerable.
     *
     * Rows are written even when the signature is wrong, because somebody
     * posting forged webhooks is a thing worth being able to see.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['flutterwave'])->default('flutterwave');
            $table->string('event')->nullable();
            $table->string('provider_reference')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            // processed | ignored_duplicate | bad_signature | unknown_reference
            $table->string('outcome');
            $table->timestamp('created_at')->nullable();

            $table->index('provider_reference');
            $table->index(['signature_valid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
