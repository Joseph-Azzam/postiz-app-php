<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_monitor_entries', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Link to scheduled post.
            $table->string('scheduled_post_id', 26)->index();

            // last known status + last check timestamp.
            $table->string('status', 20)->index(); // e.g. published, missing, retried
            $table->dateTime('last_checked_at')->nullable();

            // Optional info about last action taken.
            $table->string('last_action', 64)->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_monitor_entries');
    }
};

