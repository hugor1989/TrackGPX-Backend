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
        Schema::table('sim_cards', function (Blueprint $table) {
            // Hacer device_id nullable (por si no siempre está asignado)
            $table->foreignId('device_id')->nullable()->change();
            
            // Agregar campos del proveedor
            $table->string('sim_id')->unique()->after('id'); // ID interno (SIM001)
            $table->string('imsi')->nullable()->after('iccid');
            $table->string('subscription_type')->nullable()->after('imsi');
            $table->decimal('data_usage', 10, 2)->default(0)->after('subscription_type');
            $table->string('voice_usage')->nullable()->after('data_usage');
            $table->string('sms_usage')->nullable()->after('voice_usage');
            $table->string('plan_name')->nullable()->after('sms_usage');
            $table->string('client_name')->nullable()->after('plan_name');
            $table->string('device_brand')->nullable()->after('client_name');
            
            // Agregar campos adicionales recomendados
            $table->decimal('data_limit', 10, 2)->default(5.00)->after('data_usage');
            $table->decimal('monthly_fee', 10, 2)->default(150.00)->after('data_limit');
            $table->date('activation_date')->nullable()->after('monthly_fee');
            $table->date('expiration_date')->nullable()->after('activation_date');
            $table->text('notes')->nullable()->after('expiration_date');
            $table->string('apn')->nullable()->after('notes');
            
            // Expandir el enum de status
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->change();
            
            // Hacer iccid required y único
            $table->string('iccid')->nullable(false)->change();
            
            // Índices
            $table->index('sim_id');
            $table->index('iccid');
            $table->index('status');
            $table->index('client_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sim_cards', function (Blueprint $table) {
            // Revertir cambios
            $table->dropColumn([
                'sim_id',
                'imsi',
                'subscription_type',
                'data_usage',
                'voice_usage',
                'sms_usage',
                'plan_name',
                'client_name',
                'device_brand',
                'data_limit',
                'monthly_fee',
                'activation_date',
                'expiration_date',
                'notes',
                'apn'
            ]);
            
            $table->dropIndex(['sim_id']);
            $table->dropIndex(['iccid']);
            $table->dropIndex(['status']);
            $table->dropIndex(['client_name']);
            
            // Revertir enum
            $table->enum('status', ['active', 'inactive'])->default('active')->change();
            $table->string('iccid')->nullable()->change();
            $table->foreignId('device_id')->constrained()->onDelete('cascade')->change();
        
        });
    }
};
