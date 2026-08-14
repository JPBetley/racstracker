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
        Schema::table('members', function (Blueprint $table) {
            $table->string('uid')->nullable()->after('team_id');
            $table->boolean('is_active')->default(true)->after('position');

            $table->unique(['team_id', 'uid']);
            $table->index(['team_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'is_active']);
            $table->dropUnique(['team_id', 'uid']);
            $table->dropColumn(['uid', 'is_active']);
        });
    }
};
