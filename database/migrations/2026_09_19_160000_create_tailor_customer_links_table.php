<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The consent record.
     *
     * One row per (tailor, customer) pair, holding the answer to the only
     * question that matters about measurements: has this person agreed that
     * this tailor may see her body's dimensions.
     *
     * Consent is PER TAILOR, never global. "Share my measurements" as a single
     * switch would mean a customer who wants a second opinion from one tailor
     * has handed her file to every tailor on the platform, and she would have
     * no way to tell which of them looked.
     */
    public function up(): void
    {
        Schema::create('tailor_customer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tailor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            /*
             * granted | revoked.
             *
             * Revoked rows are kept rather than deleted, because "she took
             * this back" is a different fact from "this never happened" and
             * only one of them can be shown to either party. It is also what
             * lets a re-grant be one tap instead of a new record.
             */
            $table->enum('status', ['granted', 'revoked'])->default('granted');

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // One answer per pair. A second row would mean two answers to one
            // question, and nothing sensible to do when they disagree.
            $table->unique(['tailor_id', 'customer_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_customer_links');
    }
};
