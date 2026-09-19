<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The numbers, typed out -- for the tailor who wants to.
     *
     * Optional in exactly the way the photograph is not. A tailor who reads
     * and writes comfortably gets something searchable and comparable; one who
     * does not loses nothing, because the photograph already is the record.
     *
     * `value` is a string, not a decimal. Tailors write "38", "38 1/2",
     * "38.5" and "38-39", and a schema that accepts only the third of those
     * turns a working record into a form somebody fails to fill in. Nothing
     * computes with these -- they are read by a person with a tape measure.
     */
    public function up(): void
    {
        Schema::create('measurement_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measurement_set_id')->constrained()->cascadeOnDelete();

            // Free text, not an enum. Garments differ by culture and by
            // tailor; a fixed list of "bust, waist, hip" is a Western
            // dressmaking form, and an agbada needs none of them.
            $table->string('label');
            $table->string('value');

            // Nullable because a tailor who works only in inches will never
            // say so, and making her pick is asking a question she experiences
            // as an error message.
            $table->string('unit')->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['measurement_set_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_values');
    }
};
