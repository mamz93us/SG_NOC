<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_monitors', function (Blueprint $table) {
            $table->id();
            $table->string('sku_id', 36)->index();
            $table->string('display_name', 100);
            $table->unsignedInteger('critical_threshold')->default(5);
            $table->timestamp('last_alerted_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_monitors');
    }
};
