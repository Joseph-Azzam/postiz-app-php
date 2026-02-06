<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Mirrors NestJS mastra_messages: messages per thread (content, role, type).
     */
    public function up(): void
    {
        Schema::create('copilot_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('copilot_thread_id')->index();
            $table->text('content');
            $table->string('role', 32)->default('user'); // user | assistant | system
            $table->string('type', 32)->default('text');
            $table->timestamps();

            $table->foreign('copilot_thread_id')->references('id')->on('copilot_threads')->onDelete('cascade');
            $table->index(['copilot_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_messages');
    }
};
