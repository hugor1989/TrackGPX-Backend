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
            $table->foreignId('customer_id')
                  ->nullable()
                  ->after('id') // o donde mejor te parezca
                  ->constrained('customers') // asumiendo que tu tabla de usuarios se llama 'customers'
                  ->onDelete('set null')
                  ->comment('ID del customer dueño del dispositivo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
             $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
