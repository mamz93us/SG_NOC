<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_qos_stats', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('device_id')->nullable();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();

            $table->string('device_name')->index();
            $table->string('device_ip')->index();

            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->string('interface_name')->index();

            // output queues enqueued: 4 queues x 3 thresholds
            $table->unsignedBigInteger('q0_t1_enq')->default(0);
            $table->unsignedBigInteger('q0_t2_enq')->default(0);
            $table->unsignedBigInteger('q0_t3_enq')->default(0);
            $table->unsignedBigInteger('q1_t1_enq')->default(0);
            $table->unsignedBigInteger('q1_t2_enq')->default(0);
            $table->unsignedBigInteger('q1_t3_enq')->default(0);
            $table->unsignedBigInteger('q2_t1_enq')->default(0);
            $table->unsignedBigInteger('q2_t2_enq')->default(0);
            $table->unsignedBigInteger('q2_t3_enq')->default(0);
            $table->unsignedBigInteger('q3_t1_enq')->default(0);
            $table->unsignedBigInteger('q3_t2_enq')->default(0);
            $table->unsignedBigInteger('q3_t3_enq')->default(0);

            // output queues dropped: 4 queues x 3 thresholds
            $table->unsignedBigInteger('q0_t1_drop')->default(0);
            $table->unsignedBigInteger('q0_t2_drop')->default(0);
            $table->unsignedBigInteger('q0_t3_drop')->default(0);
            $table->unsignedBigInteger('q1_t1_drop')->default(0);
            $table->unsignedBigInteger('q1_t2_drop')->default(0);
            $table->unsignedBigInteger('q1_t3_drop')->default(0);
            $table->unsignedBigInteger('q2_t1_drop')->default(0);
            $table->unsignedBigInteger('q2_t2_drop')->default(0);
            $table->unsignedBigInteger('q2_t3_drop')->default(0);
            $table->unsignedBigInteger('q3_t1_drop')->default(0);
            $table->unsignedBigInteger('q3_t2_drop')->default(0);
            $table->unsignedBigInteger('q3_t3_drop')->default(0);

            $table->unsignedBigInteger('policer_in_profile')->default(0);
            $table->unsignedBigInteger('policer_out_of_profile')->default(0);

            $table->unsignedBigInteger('total_drops')->default(0)->index();

            $table->timestamp('polled_at')->nullable()->index();
            $table->timestamps();

            $table->index(['device_id', 'interface_name', 'polled_at'], 'qos_dev_iface_polled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_qos_stats');
    }
};
