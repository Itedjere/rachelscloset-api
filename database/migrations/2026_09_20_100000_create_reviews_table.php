<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reviews, in both directions.
     *
     * A customer reviews the tailor, and the tailor reviews the customer.
     * Two-way because the platform has two problems, not one: a tailor who
     * does not start, and a customer who will not collect. A directory that
     * only rated tailors would be asking them to carry all the risk of
     * meeting a stranger.
     *
     * `proof_ratio_snapshot` is the interesting column. See the gate in
     * App\Services\Reviews\PublishReview: a four or five star review of a
     * tailor is held when the order it praises carried almost no photographic
     * proof that the work happened. The ratio is SNAPSHOTTED because it is
     * the basis on which a decision was made, and an order's photographs can
     * be added to afterwards -- the same reasoning as an order snapshotting
     * its step labels.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->enum('direction', ['customer_to_tailor', 'tailor_to_customer']);

            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();

            /*
             * published | held.
             *
             * Held is not "rejected" and not a general moderation queue: only
             * a four or five star review of a tailor can ever land here, and
             * only when the order behind it showed almost no work. A
             * complaint publishes immediately, always -- holding a one star
             * review reads as censorship, and a tailor who did no photo work
             * is precisely the one whose bad reviews most need to be seen.
             */
            $table->enum('status', ['published', 'held'])->default('published');

            // Nullable: only meaningful on a review that faced the gate.
            $table->decimal('proof_ratio_snapshot', 5, 2)->nullable();

            $table->timestamp('published_at')->nullable();

            // Who released a held review, and when.
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            /*
             * One review per person per order. The analogue of
             * payments.provider_reference: the constraint is the rule, so a
             * double submit is a violation rather than a second opinion.
             */
            $table->unique(['order_id', 'direction']);

            // The directory reads these: a subject's published reviews.
            $table->index(['subject_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
