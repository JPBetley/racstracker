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
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('vs_minimum')->default(0)->after('alliance_id');
            $table->unsignedBigInteger('train_vs_requirement')->default(0)->after('vs_minimum');
            $table->boolean('train_desert_storm_requirement')->default(false)->after('train_vs_requirement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['vs_minimum', 'train_vs_requirement', 'train_desert_storm_requirement']);
        });
    }
};
