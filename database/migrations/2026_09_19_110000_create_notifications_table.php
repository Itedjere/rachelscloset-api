<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain table, not Laravel's polymorphic `notifications`.
     *
     * Every notification here is addressed to exactly one user, so the morph
     * columns would be a constant cost paid for a case that never arises. The
     * payload is JSON because the shape differs per type and none of it is ever
     * queried — it is read back whole, rendered, and eventually pruned.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // Drives both the unread badge and the list, which are the only
            // two queries this table ever serves.
            $table->index(['user_id', 'read_at']);

            // PruneNotifications sweeps unread rows by age, across all users.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
