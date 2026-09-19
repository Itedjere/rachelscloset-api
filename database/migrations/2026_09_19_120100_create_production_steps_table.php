<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The library of stages a garment can pass through.
     *
     * Global, not per garment type. "Cutting" means the same thing on a gown as
     * on an agbada, and a library that repeated it per garment would mean an
     * admin recording the same voice note a dozen times. Which steps apply to
     * which garment is a template's job, not this table's.
     *
     * THE VOICE NOTE IS THE POINT. Many tailors read poorly, so every step
     * carries a recording of an admin saying what it means. The written label
     * is for the rest of the interface; the recording is what a tailor
     * actually uses.
     */
    public function up(): void
    {
        Schema::create('production_steps', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('instructions')->nullable();

            /*
             * WRITE-ONCE. Replacing a step's recording stores a NEW path and
             * leaves the old file on disk untouched, because an order snapshots
             * the path it was told at assembly time. Deleting the old file
             * would silence a step in an order somebody is part-way through.
             * There is a test for this, because it is one plausible-looking
             * tidy-up away from breaking.
             */
            $table->string('voice_note_url')->nullable();

            // Retired, never deleted -- an order that snapshotted this step
            // must keep reading correctly. See garment_types.
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_steps');
    }
};
