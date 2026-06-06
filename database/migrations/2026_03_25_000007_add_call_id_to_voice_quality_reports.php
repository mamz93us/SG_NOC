<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->string('call_id', 150)->nullable()->after('id');
            }
        });

        // NOTE: a try/catch around $table->dropIndex() does NOT work — Blueprint
        // statements execute as a batch after the closure returns, so the error
        // escapes the try. Check the index exists first (also keeps a fresh
        // `migrate` from tripping where the live DB's accumulated state differs).
        $this->dropIndexIfExists('voice_quality_reports', 'voice_quality_reports_call_id_unique');
        $this->dropIndexIfExists('voice_quality_reports', 'voice_quality_reports_call_id_extension_unique');

        if (! $this->indexExists('voice_quality_reports', 'vq_call_ext_unique')) {
            Schema::table('voice_quality_reports', function (Blueprint $table) {
                $table->unique(['call_id', 'extension'], 'vq_call_ext_unique');
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('voice_quality_reports', 'vq_call_ext_unique');
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            if (Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->dropColumn('call_id');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($index);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            Schema::table($table, function (Blueprint $t) use ($index) {
                $t->dropIndex($index);
            });
        }
    }
};
