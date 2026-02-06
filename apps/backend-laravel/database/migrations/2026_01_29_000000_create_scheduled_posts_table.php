<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            // Using ULID keeps IDs short and unique (safe for shared hosting).
            $table->ulid('id')->primary();

            // Postiz groups a "thread" / multi-integration posting under a group id.
            $table->string('group', 64)->index();

            /*
             * Required scheduling state machine fields (per `.cursorrules`)
             * - status: draft/scheduled/processing/published/failed/retrying
             * - scheduled_at: when it should publish (UTC recommended)
             * - attempts/max_attempts
             * - last_error
             * - next_retry_at
             */
            $table->string('status', 20)->index(); // e.g. 'scheduled'
            $table->dateTime('scheduled_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->text('last_error')->nullable();
            $table->dateTime('next_retry_at')->nullable()->index();

            /*
             * Store the raw request payload so we can:
             * - audit what was scheduled
             * - retry idempotently
             * - keep frontend UX stable while backend evolves
             */
            $table->json('payload')->nullable();

            // Convenience fields for UI / debugging.
            $table->longText('content_preview')->nullable();
            $table->dateTime('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};

