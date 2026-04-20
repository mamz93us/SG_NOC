<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_interface_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('device_name');
            $table->string('device_ip', 45)->index();
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->string('interface_name', 40);

            // show interfaces counters  (traffic)
            $table->unsignedBigInteger('in_octets')->default(0);
            $table->unsignedBigInteger('in_ucast_pkts')->default(0);
            $table->unsignedBigInteger('in_mcast_pkts')->default(0);
            $table->unsignedBigInteger('in_bcast_pkts')->default(0);
            $table->unsignedBigInteger('out_octets')->default(0);
            $table->unsignedBigInteger('out_ucast_pkts')->default(0);
            $table->unsignedBigInteger('out_mcast_pkts')->default(0);
            $table->unsignedBigInteger('out_bcast_pkts')->default(0);

            // show interfaces counters errors
            $table->unsignedBigInteger('align_err')->default(0);
            $table->unsignedBigInteger('fcs_err')->default(0);
            $table->unsignedBigInteger('xmit_err')->default(0);
            $table->unsignedBigInteger('rcv_err')->default(0);
            $table->unsignedBigInteger('undersize')->default(0);
            $table->unsignedBigInteger('out_discards')->default(0);
            $table->unsignedBigInteger('single_col')->default(0);
            $table->unsignedBigInteger('multi_col')->default(0);
            $table->unsignedBigInteger('late_col')->default(0);
            $table->unsignedBigInteger('excess_col')->default(0);
            $table->unsignedBigInteger('carri_sen')->default(0);
            $table->unsignedBigInteger('runts')->default(0);
            $table->unsignedBigInteger('giants')->default(0);

            // derived / convenience
            $table->unsignedBigInteger('total_out_pkts')->default(0);   // ucast+mcast+bcast
            $table->unsignedBigInteger('total_in_pkts')->default(0);
            $table->decimal('drop_percentage', 8, 4)->nullable();       // total_drops / total_out_pkts * 100

            $table->timestamp('polled_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'interface_name', 'polled_at'], 'sifs_device_iface_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_interface_stats');
    }
};
