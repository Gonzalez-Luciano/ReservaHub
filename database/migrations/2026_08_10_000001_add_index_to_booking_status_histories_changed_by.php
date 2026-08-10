<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->index('changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->dropIndex(['changed_by']);
        });
    }
};
