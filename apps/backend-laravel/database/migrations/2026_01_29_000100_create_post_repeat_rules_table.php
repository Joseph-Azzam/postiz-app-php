<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_repeat_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // We repeat based on a ScheduledPost "group" (thread).
            $table->string('group', 64)->index();

            /*
             * Simple interval model to stay boring & predictable:
             * - interval_days: how many days between repeats
             * - max_repeats: how many times in total (null = unlimited)
             * - executed_count: how many runs already happened
             */
            $table->unsignedInteger('interval_days')->default(1);
            $table->unsignedInteger('executed_count')->default(0);
            $table->unsignedInteger('max_repeats')->nullable();

            // next_run_at drives cron; last_run_at is for debugging.
            $table->dateTime('next_run_at')->index();
            $table->dateTime('last_run_at')->nullable();

            // active | paused | completed
            $table->string('status', 20)->default('active')->index();

            // Optional JSON configuration (raw UI repeater payload etc.).
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_repeat_rules');
    }
};

