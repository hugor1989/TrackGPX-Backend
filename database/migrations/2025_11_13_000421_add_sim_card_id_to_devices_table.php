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
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('sim_card_id')
                  ->nullable()
                  ->constrained('sim_cards')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
             $table->dropForeign(['sim_card_id']);
            $table->dropColumn('sim_card_id');
        });
    }
};
