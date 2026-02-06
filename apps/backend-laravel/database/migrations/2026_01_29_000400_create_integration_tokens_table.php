<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Generic integration identifier (e.g. "twitter", "facebook") and account id.
            $table->string('provider', 64)->index();
            $table->string('account_identifier', 128)->index();

            // Token metadata (do not store real secrets in plain text in production).
            $table->json('payload')->nullable();

            // Expiration tracking.
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('last_refreshed_at')->nullable();

            // active | revoked
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};

