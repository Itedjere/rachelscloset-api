<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The checklist for one order. This is the product.
     *
     * EVERYTHING IS SNAPSHOTTED. The label, the instructions and the path to
     * the recording are copied here when the order is assembled, and never
     * read back from the library afterwards.
     *
     * Not an optimisation -- a correctness rule. The customer was TOLD
     * something: she read "Beading" and tapped play and heard an admin
     * explain what that meant. An admin tidying up the wording next month, or
     * re-recording that step, must not reach back and rewrite the timeline she
     * already read. It is the same reasoning that makes an order snapshot its
     * commission percentage.
     *
     * It is also why a step's voice note is write-once (see ProductionStep):
     * the old file has to stay on disk, because these rows still point at it.
     */
    public function up(): void
    {
        Schema::create('order_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * Provenance only -- which library step this came from. Nullable
             * and null-on-delete precisely because nothing here reads through
             * it: the snapshot above is the record, and it must survive the
             * library changing underneath it.
             */
            $table->foreignId('production_step_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->unsignedInteger('position');

            // The snapshot.
            $table->string('label');
            $table->text('instructions')->nullable();
            $table->string('voice_note_url')->nullable();

            $table->timestamp('completed_at')->nullable();
            // Who ticked it. Kept for a dispute about when work actually happened.
            $table->foreignId('completed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['order_id', 'position']);
            $table->index(['order_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_steps');
    }
};
