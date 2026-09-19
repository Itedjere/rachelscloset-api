<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers an admin can change without a deploy.
 *
 * Key and value, deliberately untyped. Everything in here is an operational
 * judgement — what a subscription costs, how long the grace period runs, how
 * much photo proof a five-star review needs — and finding out one of them is
 * wrong should not need a developer.
 *
 * Only `updated_at` is tracked: rows are seeded once and then edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
