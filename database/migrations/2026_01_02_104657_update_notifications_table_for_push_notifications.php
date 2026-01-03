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
        Schema::table('notifications', function (Blueprint $table) {
            // Agregar nuevos campos
            $table->string('type')->after('customer_id'); // tipo de notificación
            $table->json('data')->nullable()->after('message'); // datos adicionales
            $table->boolean('is_read')->default(false)->after('data'); // si fue leída
            $table->timestamp('read_at')->nullable()->after('is_read'); // cuándo fue leída
            
            // Renombrar 'sent' a 'push_sent' para ser más claro
            $table->renameColumn('sent', 'push_sent');
            
            // Renombrar 'sent_at' a 'push_sent_at'
            $table->renameColumn('sent_at', 'push_sent_at');
            
            // Índices para búsquedas rápidas
            $table->index('type');
            $table->index('is_read');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['type', 'data', 'is_read', 'read_at']);
            $table->renameColumn('push_sent', 'sent');
            $table->renameColumn('push_sent_at', 'sent_at');
            $table->dropIndex(['type']);
            $table->dropIndex(['is_read']);
            $table->dropIndex(['created_at']);
        });
    }
};
