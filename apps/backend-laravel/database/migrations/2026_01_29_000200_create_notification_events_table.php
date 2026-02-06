<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Optional foreign keys – we keep them scalar for now for flexibility.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // Logical grouping key for digests (e.g. "daily", "weekly", "activity").
            $table->string('channel', 64)->default('default')->index();

            // Event type + payload.
            $table->string('type', 128)->index();
            $table->json('payload')->nullable();

            // Mark when the event has been included in a digest batch.
            $table->string('digest_batch_id', 64)->nullable()->index();
            $table->dateTime('digested_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};

