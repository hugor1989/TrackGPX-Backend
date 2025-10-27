<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Agregar campos que te faltan manteniendo los que ya tienes
            $table->string('serial_number')->unique()->nullable()->after('imei');
            $table->string('activation_code')->nullable()->after('serial_number');
            $table->string('manufacturer')->nullable()->after('activation_code');
            $table->string('model')->nullable()->after('manufacturer');
            $table->json('config_parameters')->nullable()->after('model');
            $table->timestamp('activated_at')->nullable()->after('last_connection');
            
            // Modificar el enum status para incluir estado 'pending'
            DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM('active', 'inactive', 'disconnected', 'pending') DEFAULT 'pending'");

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'serial_number',
                'activation_code', 
                'manufacturer',
                'model',
                'config_parameters',
                'activated_at'
            ]);
            
            // Revertir el enum
            DB::statement("ALTER TABLE devices MODIFY COLUMN status ENUM('active', 'inactive', 'disconnected') DEFAULT 'active'");
        
        });
    }
};
