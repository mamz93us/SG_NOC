<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The warm sub-line under "Good morning, {name}" on the home portal.
 *
 * Editable in admin so the wording can be changed without a deploy — the whole
 * company reads it every morning, so somebody will want to.
 *
 * `time_of_day` narrows a line to morning/afternoon/evening (null = any) and
 * `day_of_week` to a specific day (null = any), which is enough for "Happy
 * Thursday" without inventing a scheduling language. App\Services\Home\Greeter
 * falls back to a built-in set when this table is empty, so an empty table
 * never yields a blank greeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greeting_lines', function (Blueprint $table) {
            $table->id();
            $table->string('text', 200);
            $table->string('text_ar', 200)->nullable();
            // morning | afternoon | evening | null (any)
            $table->string('time_of_day', 20)->nullable()->index();
            // 0=Sunday … 6=Saturday, null = any day
            $table->unsignedTinyInteger('day_of_week')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greeting_lines');
    }
};
