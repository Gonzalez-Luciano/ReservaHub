<?php

use App\Support\PaymentWindowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('payment_expires_at')->nullable()->after('deposit_amount');
        });

        (new PaymentWindowBackfill)->run();
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('payment_expires_at');
        });
    }
};
