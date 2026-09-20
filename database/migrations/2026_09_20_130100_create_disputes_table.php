<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer saying something is wrong, and what was decided about it.
     *
     * THE PLATFORM DOES NOT ADJUDICATE. It freezes the money and hands the
     * admin both phone numbers; the decision is made by ringing both parties
     * and talking to them. That is how this business already works, and a
     * form asking a tailor to upload counter-evidence would be inventing a
     * process nobody asked for.
     *
     * What the platform is actually for here is the money. An open dispute
     * stops escrow releasing -- by the timer, by the nightly sweep, by the
     * tailor's own button, and by the customer accidentally confirming she is
     * happy. Without that freeze a dispute is a complaint form.
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * The customer, or an admin opening one for her after a phone
             * call -- she may well be ringing precisely because she cannot
             * work the app. The role of this account is what the screen reads
             * to say "opened by Rachel on her behalf".
             */
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');

            $table->enum('status', ['open', 'resolved'])->default('open');

            /*
             * What was decided, after the calls. `withdrawn` is not a
             * judgement: it is for a customer who rings back to say the
             * parcel turned up after all.
             */
            $table->enum('outcome', ['refunded', 'released', 'withdrawn'])->nullable();
            $table->decimal('refunded_amount', 14, 2)->nullable();
            $table->text('resolution_note')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            /*
             * ONE OPEN DISPUTE PER ORDER, which the schema cannot express:
             * MySQL has no partial unique index, and a unique on
             * (order_id, status) would forbid a second resolved one. Enforced
             * in DisputeController, the only thing that opens one -- the same
             * shape as StepTemplate::defaultFor(), and said here rather than
             * pretended otherwise.
             */
            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
