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
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();

            // Relación: Un contacto pertenece a un Cliente (o Usuario)
            // Ajusta 'user_id' o 'customer_id' según tu sistema
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');

            $table->string('name');
            $table->string('phone', 20); // Para WhatsApp/SMS
            $table->string('email')->nullable();
            $table->string('relationship')->nullable(); // "Esposa", "Jefe", etc.

            // Configuración de notificaciones
            $table->boolean('notify_sms')->default(false);
            $table->boolean('notify_whatsapp')->default(true);
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_call')->default(false); // Para integraciones futuras (Twilio/Asterisk)

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
