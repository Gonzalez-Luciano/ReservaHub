<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->string('status');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('application_outcome')->nullable();
            $table->string('failure_reason')->nullable();
            $table->jsonb('last_snapshot')->nullable();
            $table->timestamp('last_reconcile_attempt_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('business_id');
            $table->index('booking_id');
            $table->index(['status', 'last_reconcile_attempt_at']);
        });

        // Como máximo un intento activo por reserva. El índice parcial es el
        // que sostiene el invariante: la comprobación en PHP es conveniencia.
        DB::statement(
            "create unique index payments_one_pending_per_booking on payments (booking_id) where status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
