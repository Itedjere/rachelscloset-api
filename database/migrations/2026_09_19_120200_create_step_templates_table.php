<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An ordered arrangement of steps for one kind of garment.
     *
     * One table for two things, discriminated by `owner_id`:
     *
     *   owner_id IS NULL  the admin's default for this garment type
     *   owner_id = <id>   a tailor's own saved arrangement
     *
     * One table rather than two because they are the same shape and are read
     * the same way -- assembling an order asks "does this tailor have her own
     * arrangement for this garment, and if not what is the default". Two
     * tables would make that a union for no gain.
     */
    public function up(): void
    {
        Schema::create('step_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_type_id')->constrained()->cascadeOnDelete();

            /*
             * Null means this is the admin's default. Cascades on delete so a
             * tailor who leaves takes her own arrangements with her; the
             * default, owned by nobody, survives.
             */
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->timestamps();

            /*
             * One arrangement per tailor per garment type.
             *
             * Note this does NOT constrain the admin default: MySQL permits
             * any number of rows where part of a unique key is NULL. There
             * being exactly one default per garment type is enforced in
             * StepTemplate::defaultFor(), which is the only thing that writes
             * one, and that is the honest place for it -- a schema that half
             * enforces a rule is worse than one that clearly does not.
             */
            $table->unique(['garment_type_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_templates');
    }
};
