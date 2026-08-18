<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado del proveedor simulado. NO es dato de dominio: ninguna consulta de
     * negocio, Policy ni Resource lo lee. Existe para que la reconciliación
     * compare dos almacenes realmente independientes.
     */
    public function up(): void
    {
        Schema::create('simulated_provider_payments', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('status');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->jsonb('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_provider_payments');
    }
};
