<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_breaks', function (Blueprint $table) {
            $table->index('schedule_id');
        });

        Schema::table('time_offs', function (Blueprint $table) {
            $table->index(['employee_id', 'starts_at']);
        });

        Schema::table('employee_service', function (Blueprint $table) {
            $table->index('service_id');
        });

        Schema::table('employee_invitations', function (Blueprint $table) {
            $table->index('invited_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_breaks', function (Blueprint $table) {
            $table->dropIndex(['schedule_id']);
        });

        Schema::table('time_offs', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'starts_at']);
        });

        Schema::table('employee_service', function (Blueprint $table) {
            $table->dropIndex(['service_id']);
        });

        Schema::table('employee_invitations', function (Blueprint $table) {
            $table->dropIndex(['invited_by_id']);
        });
    }
};
