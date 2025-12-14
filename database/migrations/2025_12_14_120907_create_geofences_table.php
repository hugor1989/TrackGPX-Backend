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
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            
            // Información básica
            $table->string('name'); // Nombre de la geocerca (ej: "Casa", "Oficina")
            $table->enum('type', ['circle', 'polygon'])->default('circle');
            $table->string('icon')->default('location'); // Ícono del marcador
            $table->string('color')->default('#007AFF'); // Color del área
            
            // Geometría para círculo
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lon', 10, 7)->nullable();
            $table->integer('radius')->nullable()->comment('Radio en metros');
            
            // Geometría para polígono (JSON con array de coordenadas)
            $table->json('polygon_points')->nullable();
            
            // Configuración de alertas
            $table->boolean('alert_on_enter')->default(true);
            $table->boolean('alert_on_exit')->default(true);
            
            // Horario de funcionamiento (opcional)
            $table->boolean('schedule_enabled')->default(false);
            $table->json('schedule_days')->nullable(); // ["monday", "tuesday", ...]
            $table->time('schedule_start')->nullable();
            $table->time('schedule_end')->nullable();
            
            // Estado
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Índices
            $table->index('device_id');
            $table->index('customer_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
