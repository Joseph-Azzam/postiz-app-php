<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add user scoping and display fields for Postiz integrations list (mirrors NestJS Integration).
     */
    public function up(): void
    {
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            $table->string('name', 255)->nullable()->after('account_identifier');
            $table->string('picture', 512)->nullable()->after('name');
            $table->string('profile', 255)->nullable()->after('picture'); // username/handle
            $table->text('refresh_token')->nullable()->after('payload');
            $table->json('posting_times')->nullable()->after('refresh_token');
            $table->boolean('disabled')->default(false)->after('status');
            $table->boolean('in_between_steps')->default(false)->after('disabled');
            $table->string('additional_settings', 1024)->nullable()->after('in_between_steps');
        });

        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id', 'name', 'picture', 'profile', 'refresh_token',
                'posting_times', 'disabled', 'in_between_steps', 'additional_settings',
            ]);
        });
    }
};
