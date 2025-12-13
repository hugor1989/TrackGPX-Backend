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
        Schema::create('device_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            $table->string('custom_name')->nullable();
            $table->string('color', 7)->default('#434240'); // HEX color
            $table->string('marker_icon')->nullable(); // URL o nombre del icono
            $table->string('vehicle_image')->nullable(); // URL de la imagen
            $table->enum('route_type', ['car', 'walking', 'bicycle'])->default('car');
            $table->boolean('tracking_disabled')->default(false);
            $table->boolean('sharing_enabled')->default(true);
            $table->boolean('show_live_position')->default(true);
            $table->boolean('show_pause_markers')->default(true);
            $table->boolean('show_alerts')->default(true);
            $table->boolean('fixed_date_range')->default(false);
            $table->timestamp('date_range_from')->nullable();
            $table->timestamp('date_range_to')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_configurations');
    }
};
