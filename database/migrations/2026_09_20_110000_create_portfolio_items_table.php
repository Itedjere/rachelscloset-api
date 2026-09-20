<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tailor's public gallery.
     *
     * TWO SOURCES, ONE TABLE.
     *
     * The tailor uploads her own, so a profile is not empty on the day she
     * joins -- a directory entry with no pictures wins nobody, and the QR
     * card in Section 16 points straight at this page.
     *
     * And the customer uploads to a finished order: she wears the dress to a
     * party, takes photographs, and puts them on the order. Those appear in
     * the tailor's gallery.
     *
     * That second source is the good one, and not only because it costs the
     * tailor no effort. A photograph of the finished garment being *worn* is
     * the thing a prospective customer actually wants to see, and it is
     * evidence in a way a studio shot is not. It also needs no consent
     * machinery at all: the customer choosing to upload it IS the consent.
     * That is why this does not reuse `order_step_photos`, which Section 10
     * deliberately confined to the two people on the order -- those are one
     * customer's cloth on one tailor's table, photographed mid-construction,
     * and they stay private.
     *
     * `hidden_at` is the tailor's control over her own shopfront. She cannot
     * delete a customer's photograph, but she can take it off her gallery --
     * a public page somebody else can post to unconditionally is not a page
     * anybody would put on a business card.
     */
    public function up(): void
    {
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();

            // Whose gallery this belongs to.
            $table->foreignId('tailor_id')->constrained('users')->cascadeOnDelete();

            /*
             * Null means the tailor uploaded it to her profile directly.
             * Otherwise it is the finished order the customer photographed,
             * which is also what the per-order cap counts against.
             */
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('path');
            $table->string('caption')->nullable();

            // Dense 1..n, reordered by PUTting the whole array. Arrows, never
            // drag-and-drop -- the same rule as the step arrangement.
            $table->unsignedInteger('position')->default(0);

            // Set by the tailor. The row stays; only the gallery drops it.
            $table->timestamp('hidden_at')->nullable();

            $table->timestamps();

            // The gallery: one tailor's visible items, in order.
            $table->index(['tailor_id', 'hidden_at', 'position']);
            // The per-order cap, and the customer's own list.
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_items');
    }
};
