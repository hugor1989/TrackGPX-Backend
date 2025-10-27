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
            // Primero eliminar la foreign key existente
            $table->dropForeign(['vehicle_id']);
            
            // Luego hacer la columna nullable
            $table->foreignId('vehicle_id')->nullable()->change();
            
            // Finalmente volver a agregar la foreign key
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
             // Eliminar foreign key
            $table->dropForeign(['vehicle_id']);
            
            // Hacer la columna NOT NULL
            $table->foreignId('vehicle_id')->nullable(false)->change();
            
            // Volver a agregar la foreign key
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
        });
    }
};
