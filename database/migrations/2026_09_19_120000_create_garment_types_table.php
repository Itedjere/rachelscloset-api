<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of thing a tailor makes: agbada, iro and buba, a wedding gown.
     *
     * An admin curates this list. It is small, changes rarely, and is the first
     * choice a customer makes when placing an order -- so it is ordered by hand
     * rather than alphabetically, and the common ones sit at the top.
     */
    public function up(): void
    {
        Schema::create('garment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            /*
             * Printed in a URL once the directory can be filtered by garment.
             * Generated once and never moved by a rename, for the same reason
             * a tailor's slug is not: it ends up in somebody's bookmark.
             */
            $table->string('slug')->unique();

            $table->string('description')->nullable();

            // Hand-ordered. Dense 1..n, rewritten whole -- see step_template_items.
            $table->unsignedInteger('position')->default(0);

            /*
             * Retired, never deleted. Orders already placed against a garment
             * type must keep reading correctly years later, and a foreign key
             * that disappears takes a customer's history with it.
             */
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            $table->index(['retired_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garment_types');
    }
};
