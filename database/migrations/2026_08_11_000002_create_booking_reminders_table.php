<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
    }
};
