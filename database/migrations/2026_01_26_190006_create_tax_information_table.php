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
        Schema::create('tax_information', function (Blueprint $table) {
            $table->id();
            // Asumiendo que usas 'customers' o 'users'
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');

            $table->string('razon_social'); // Nombre legal exacto
            $table->string('rfc', 13);      // RFC (Persona Física o Moral)
            $table->string('regimen_fiscal'); // El código (Ej: 601, 626)
            $table->string('codigo_postal', 5); // CRÍTICO para factura 4.0
            $table->string('direccion')->nullable(); // Calle y número
            $table->string('correo_facturacion'); // Donde llega el XML/PDF
            $table->string('uso_cfdi')->default('G03'); // Gastos en general

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_information');
    }
};
