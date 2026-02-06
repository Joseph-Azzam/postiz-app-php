<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autopost_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Identifier of the connected account/channel (string to stay backend-agnostic).
            $table->string('account_identifier', 128)->index();

            // When this rule should run next, and when it last ran.
            $table->dateTime('next_run_at')->index();
            $table->dateTime('last_run_at')->nullable();

            // enabled | disabled
            $table->string('status', 20)->default('enabled')->index();

            // Arbitrary configuration / filters (from UI).
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopost_rules');
    }
};

