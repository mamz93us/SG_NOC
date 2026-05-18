<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->string('account_number', 64)->nullable()->after('provider');
            $table->enum('connection_type', ['copper', 'fiber', '5g', 'dedicated'])->nullable()->after('account_number');
            $table->enum('customer_type', ['business', 'home'])->nullable()->after('connection_type');
            $table->enum('payment_type', ['prepaid', 'postpaid'])->nullable()->after('customer_type');
            $table->unsignedTinyInteger('billing_day')->nullable()->after('payment_type');
            $table->string('package', 120)->nullable()->after('billing_day');

            $table->index('account_number');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->dropIndex(['account_number']);
            $table->dropIndex(['provider']);
            $table->dropColumn([
                'account_number',
                'connection_type',
                'customer_type',
                'payment_type',
                'billing_day',
                'package',
            ]);
        });
    }
};
