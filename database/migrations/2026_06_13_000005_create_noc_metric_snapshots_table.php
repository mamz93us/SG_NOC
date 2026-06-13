<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic hourly metric snapshots for counters that have no native history
     * (e.g. VoIP extensions registered, trunks up, active calls). Lets the NOC
     * overview chart them over time. Captured by noc:snapshot-availability.
     */
    public function up(): void
    {
        Schema::create('noc_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('metric', 50)->index();   // voip_ext_registered, voip_trunks_up, …
            $table->double('value')->default(0);
            $table->unsignedInteger('branch_id')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['metric', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noc_metric_snapshots');
    }
};
