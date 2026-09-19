<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts.
 *
 * Two departures from the framework default, both forced by who uses this:
 *
 * `email` is **nullable**. Many tailors have never had an address, and
 * requiring one would shut out exactly the people the platform exists for. The
 * unique index stays — MySQL permits any number of NULLs in one — so an address
 * is still unique when there is one.
 *
 * `phone` is **required and unique**, because it is the username. Two accounts
 * sharing a number would make "who is signing in?" a question with no answer.
 * It is stored normalised (see App\Rules\NigerianPhone) so the same number
 * written two ways cannot become two accounts.
 *
 * The `password` column holds a bcrypt hash of a six-digit PIN. There is no
 * separate PIN column: it is a secret somebody types to sign in, so it is the
 * password, and a second column would mean two code paths for hashing,
 * resetting and rate-limiting the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Optional, unique when present. See the note above.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();

            // The PIN, hashed. Nullable because a tailor-created customer has
            // no PIN until she claims the profile herself.
            $table->string('password')->nullable();

            $table->enum('role', ['customer', 'tailor', 'admin'])->index();

            // The username. Required, and the column is not null.
            $table->string('phone', 20)->unique();

            $table->string('avatar_url')->nullable();

            $table->enum('status', ['active', 'suspended'])->default('active')->index();

            /*
             * Denormalised from the suspension history that arrives in a later
             * section. It lives on the row because the middleware reads it on
             * every single request, and that must not cost a second query.
             * Null means permanent, or in good standing.
             */
            $table->timestamp('suspended_until')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        /*
         * Keyed on email, which is the framework's assumption and cannot be
         * changed without replacing the broker. An account with no address
         * files its token under its phone number instead — see
         * User::getEmailForPasswordReset(), which exists for exactly this.
         */
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
