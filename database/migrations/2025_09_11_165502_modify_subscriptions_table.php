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
         Schema::table('subscriptions', function (Blueprint $table) {
            // Hacer device_id nullable
            $table->foreignId('device_id')->nullable()->change();

            // Cambiar enum status (requiere Doctrine DBAL)
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled'])->default('pending')->change();

            // Nuevas columnas
            $table->string('payment_reference')->nullable()->after('status');
            $table->timestamp('paid_at')->nullable()->after('payment_reference');
            
            // Hacer fechas opcionales
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // ⚠️ Aquí revertirías los cambios si haces rollback
            $table->dropColumn(['payment_reference', 'paid_at']);
        });
    }
};
