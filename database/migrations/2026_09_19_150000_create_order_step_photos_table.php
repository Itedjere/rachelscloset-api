<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof that a stage actually happened.
     *
     * The tracker says "Sewing done" because a tailor tapped a circle. This is
     * the table that makes that claim checkable: a photograph of the work,
     * attached to the stage it belongs to, taken on the phone that was already
     * in her hand.
     *
     * It is also the input to the review gate in Section 13. Four- and
     * five-star reviews are held unless the order carried real photo work,
     * because the thing that gate exists to stop is rating inflation from
     * orders that never happened.
     */
    public function up(): void
    {
        Schema::create('order_step_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_step_id')->constrained()->cascadeOnDelete();

            /*
             * DENORMALISED, deliberately, and the plan says so.
             *
             * The proof ratio is "how many of this order's stages carry a
             * photograph", which without this column is a join through
             * order_steps on every review decision and every order page. The
             * cost is one integer that must not disagree with
             * order_step_id -> order_id; nothing but the controller writes a
             * row, and it copies the value off the step it was handed.
             */
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('path');

            // Which account uploaded it. The tailor today; kept because a
            // dispute is about who said what happened, not only what.
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The proof ratio, in one indexed query.
            $table->index('order_id');
            $table->index('order_step_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_step_photos');
    }
};
