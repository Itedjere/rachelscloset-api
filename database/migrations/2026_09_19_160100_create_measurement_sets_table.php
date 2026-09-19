<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer's measurements, as they were actually taken.
     *
     * THE PHOTOGRAPH IS THE RECORD. Not a fallback, not an attachment to a
     * form -- the record. A tailor with a tape around somebody's waist writes
     * the number in a paper book with a biro, and many tailors on this
     * platform read poorly enough that asking them to transcribe twelve
     * numbers into labelled boxes would mean either wrong numbers or no
     * numbers. Photographing the page she already wrote is the whole design.
     *
     * measurement_values exists alongside for the tailor who does want to type
     * them, and is optional in exactly the way the photograph is not.
     *
     * A set is never edited. A body changes, and last year's numbers are how
     * you know by how much, so a new measuring is a new row.
     */
    public function up(): void
    {
        Schema::create('measurement_sets', function (Blueprint $table) {
            $table->id();

            // Whose body. Not "whose account" -- an unclaimed profile has
            // measurements long before it has a PIN.
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            /*
             * Who measured. Kept even after she is unlinked, because it is
             * what makes "only the tailor who recorded them" enforceable on an
             * unclaimed profile -- see MeasurementAccess.
             */
            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('photo_url')->nullable();

            // "Wedding, Nov" -- how she tells two sets apart at a glance.
            $table->string('label')->nullable();
            $table->text('notes')->nullable();

            // When the measuring happened, which is not when the row was
            // created: a tailor photographs the book that evening.
            $table->date('taken_on')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_sets');
    }
};
