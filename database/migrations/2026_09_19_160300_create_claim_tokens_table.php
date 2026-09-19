<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How somebody takes ownership of a profile a tailor created for her.
     *
     * Nothing in this project sends an SMS or requires an email address, so
     * none of the ordinary invite mechanisms exist. What does exist is that
     * the tailor and the customer are standing together when measurements are
     * taken, which is why all three channels are free and two of them send
     * nothing at all:
     *
     *   1. a QR code on the tailor's screen, scanned off it;
     *   2. a wa.me deep link from the tailor's OWN WhatsApp;
     *   3. six digits read down an ordinary phone call.
     *
     * One row serves all three: the QR and the link carry the long token, the
     * phone call carries the code, and both resolve to the same row.
     *
     * BOTH ARE STORED HASHED and returned in plaintext exactly once, at
     * issue. Six digits is a million guesses, which is only safe because the
     * endpoint is throttled and the row expires; storing them readable would
     * mean a database leak is a list of live account takeovers.
     */
    public function up(): void
    {
        Schema::create('claim_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Unique so a lookup by token is an index hit rather than a scan
            // over every outstanding invite on the platform.
            $table->string('token_hash')->unique();
            $table->string('code_hash');

            /*
             * Section 11 issues `claim`. The same three channels are the PIN
             * reset route -- the thing that stops a tailor with no email
             * being locked out forever -- and that section adds `pin_reset`
             * here rather than a second parallel mechanism.
             */
            $table->enum('purpose', ['claim', 'pin_reset'])->default('claim');

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            // Which tailor invited whom. An invite is somebody vouching for a
            // stranger's phone number, so it is worth being able to see who.
            $table->foreignId('issued_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_tokens');
    }
};
