<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_signature_templates', function (Blueprint $table) {
            // 'all' (any gender), 'male', or 'female'. Lets a domain have a
            // male + female variant; findBest() picks by the employee's gender.
            $table->string('gender', 10)->default('all')->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_signature_templates', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
