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
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            
            $table->string('command'); // locate, restart, lock, unlock, sos
            $table->json('parameters')->nullable();
            $table->enum('status', ['pending', 'sent', 'executed', 'failed'])->default('pending');
            
            $table->timestamp('sent_at');
            $table->timestamp('executed_at')->nullable();
            $table->text('response')->nullable();
            
            $table->timestamps();
            
            $table->index('device_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
