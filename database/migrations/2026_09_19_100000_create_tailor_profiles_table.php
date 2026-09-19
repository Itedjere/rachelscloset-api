<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop behind a tailor's account.
 *
 * Separate from `users` because only one role in three has one, and because
 * almost every column here is public-facing where the users row is not.
 *
 * `slug` is the address of her public profile, and it is printed on a QR code
 * on a business card that ends up in somebody's purse. That makes it the one
 * column here that must never change silently: a slug that moves turns printed
 * cardboard into a 404, permanently. It is generated once and only ever changed
 * deliberately.
 *
 * The two denormalised figures at the bottom are recomputed from scratch rather
 * than adjusted, so a deleted review cannot leave them drifting from the truth.
 * They are stored at all because they appear on every card in the directory,
 * and recomputing them per row would put two aggregates behind every result.
 *
 * Bank and payout columns are not here yet; they arrive with payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('business_name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();

            /*
             * Where she is. Split into a free-text area and a state chosen from
             * a fixed list, so "find a tailor near me" can be an indexed
             * equality check on the state rather than a LIKE over one string.
             * Distance is the problem this platform exists to solve, so the
             * column it turns on is worth getting right at the start.
             */
            $table->string('location')->nullable();
            $table->string('state')->nullable()->index();

            /*
             * The number customers actually reach her on, which is not
             * necessarily the one she signs in with. Optional: until she fills
             * it in, the profile shows the account phone.
             */
            $table->string('whatsapp_phone', 20)->nullable();

            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->unsignedInteger('orders_completed')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_profiles');
    }
};
