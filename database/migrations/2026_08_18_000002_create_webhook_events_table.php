<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_event_id');
            $table->string('payment_external_id')->nullable();
            $table->jsonb('payload');
            $table->string('status');
            $table->string('outcome_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Identidad del evento garantizada por la base, no por PHP.
            $table->unique(['provider', 'external_event_id']);
            $table->index(['status', 'received_at']);
            $table->index('payment_external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
