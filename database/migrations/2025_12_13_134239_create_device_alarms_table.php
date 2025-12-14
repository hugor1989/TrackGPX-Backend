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
        Schema::create('device_alarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            
            // Alarmas básicas
            $table->boolean('alarm_removal')->default(true);
            $table->boolean('alarm_low_battery')->default(true);
            $table->boolean('alarm_vibration')->default(false);
            
            // Alarma de velocidad
            $table->boolean('alarm_speed')->default(false);
            $table->integer('speed_limit')->nullable();
            
            // Alarma de geocerca
            $table->boolean('alarm_geofence')->default(false);
            
            $table->timestamps();
            
            $table->unique('device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_alarms');
    }
};
