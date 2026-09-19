<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One step's place in one arrangement.
     *
     * `position` is a dense 1..n integer, and it is only ever rewritten whole:
     * the client PUTs the complete ordered array of step ids and the server
     * renumbers from one. No gap arithmetic, no fractional indices, and any
     * rearrangement -- a swap, a move, a removal -- is the same single
     * idempotent request. Retrying it cannot corrupt the order.
     */
    public function up(): void
    {
        Schema::create('step_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_template_id')->constrained()->cascadeOnDelete();

            /*
             * Restricted, not cascaded. A step that is part of somebody's
             * arrangement cannot be deleted out from under it -- which is why
             * production_steps retires rather than deletes.
             */
            $table->foreignId('production_step_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('position');
            $table->timestamps();

            // A step appears at most once in an arrangement.
            $table->unique(['step_template_id', 'production_step_id']);
            $table->index(['step_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_template_items');
    }
};
