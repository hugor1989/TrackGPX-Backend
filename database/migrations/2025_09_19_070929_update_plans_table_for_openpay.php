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
         Schema::table('plans', function (Blueprint $table) {
            // Cambiar duration_days a interval_count (más estándar con OpenPay)
            $table->renameColumn('duration_days', 'interval_count');
            
            // Agregar nuevos campos necesarios para OpenPay
            $table->string('openpay_plan_id')->nullable()->after('id');
            $table->enum('interval', ['day', 'week', 'month', 'year'])->default('month')->after('price');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('description');
            $table->string('currency', 3)->default('MXN')->after('price');
            
            // Agregar índice único para openpay_plan_id
            $table->unique('openpay_plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Revertir los cambios
            $table->renameColumn('interval_count', 'duration_days');
            $table->dropColumn(['openpay_plan_id', 'interval', 'status', 'currency']);
            $table->dropUnique('plans_openpay_plan_id_unique');
        });
    }
};
