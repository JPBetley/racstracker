<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('device_id');
            $table->string('game_uid');
            $table->string('zone')->nullable();
            $table->text('login_key');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_issued_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('app_version');
            $table->string('version_code');
            $table->timestamp('last_login_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_accounts');
    }
};
