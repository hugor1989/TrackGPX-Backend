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
            // Agregamos latitud y longitud con suficientes decimales para GPS
            // nullable() es importante porque un dispositivo nuevo no tiene ubicación aún
            $table->decimal('last_latitude', 10, 7)->nullable()->after('last_connection');
            $table->decimal('last_longitude', 11, 7)->nullable()->after('last_latitude');
            
            // Recomendado: Agregar velocidad y dirección para pintar el marker correctamente al inicio
            $table->float('last_speed')->nullable()->default(0)->after('last_longitude');
            $table->float('last_heading')->nullable()->default(0)->after('last_speed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['last_latitude', 'last_longitude', 'last_speed', 'last_heading']);  
        });
    }
};
