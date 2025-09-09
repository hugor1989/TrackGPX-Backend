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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('action'); // ej: CREATE_CUSTOMER, UPDATE_DEVICE_STATUS
            $table->string('entity_type'); // ej: customer, device, vehicle...
            $table->unsignedBigInteger('entity_id'); // id de la entidad afectada
            $table->text('description')->nullable(); // detalle de la acción
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
