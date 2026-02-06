<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_streaks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->index();

            $table->unsignedInteger('current_streak_days')->default(0);
            $table->dateTime('last_post_at')->nullable();
            $table->dateTime('last_reminder_at')->nullable();

            // active | paused
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_streaks');
    }
};

