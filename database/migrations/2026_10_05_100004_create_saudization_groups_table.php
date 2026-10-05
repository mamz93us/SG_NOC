<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The professional groups the ministry sets a Saudization percentage for, and
 * the percentage each one needs: today's, and up to two it has announced for
 * later.
 *
 * A group counts the employees in ONE of Oracle's job categories. That is how
 * the seventeen groups in HR's table of 2026-10-05 line up with the eighteen
 * categories Oracle sends — one to one, with "Customs Clearance" (2 people)
 * belonging to no group. Oracle's "Health" is the laboratory staff: every
 * profession in it is a medical laboratory specialist or technician.
 *
 * The rows are HR's to edit. They are seeded once, into an empty table, so a
 * re-run never puts back a percentage somebody has changed.
 */
return new class extends Migration
{
    /** name_ar, name_en, Oracle job category, required %, [next %, from], [future %, from] */
    private const GROUPS = [
        ['المهن الهندسية', 'Engineering professions', 'Engineers', 30],
        ['وظائف هندسة الاتصالات و تقنية المعلومات', 'Communications engineering and IT', 'Comm eng &information tech', 25],
        ['وظائف تطوير التطبيقات و البرمجة و التحليل', 'Application development, programming and analysis', 'AppDev&prog&analysis', 25],
        ['وظائف الدعم الفني و الوظائف الفنية للاتصالات', 'Technical support and communications technical jobs', 'Technical & comm Support', 25],
        ['المهن المحاسبية', 'Accounting', 'Accountant', 40, [50, '2026-10-01'], [60, '2027-10-01']],
        ['المهن القانونية', 'Legal', 'Legal', 70],
        ['مهن التسويق', 'Marketing', 'Marketing', 60],
        ['المهن الإدارية المساندة', 'Administrative support', 'Administrative support', 100],
        ['الأجهزة الطبية (مهن المهندسين والفنيين)', 'Medical equipment (engineers and technicians)', 'Medical Equip/Engin-Technical', 50],
        ['مهن إدارة المشاريع', 'Project management', 'Projects', 40, [70, '2027-02-01']],
        ['مهن المبيعات', 'Sales', 'Sales', 60],
        ['مهن المشتريات', 'Purchasing', 'Purchases', 70],
        ['مهن المختبرات', 'Laboratories', 'Health', 70],
        ['مهن الاشعة', 'Radiology', 'X-Ray', 65],
        ['طب الأسنان', 'Dentistry', 'Dentistry', 55],
        ['المهن الفنية الهندسية', 'Engineering technical professions', 'Engineering Technical', 30],
        ['مهن الصيدلة', 'Pharmacy', 'Pharmacist', 55],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('saudization_groups')) {
            Schema::create('saudization_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name_ar', 255);
                $table->string('name_en', 255)->nullable();
                // The Oracle job category whose people this group counts.
                // Null for a group Oracle has no category for yet.
                $table->string('job_category', 255)->nullable();
                $table->decimal('required_percent', 5, 2);
                // What the ministry has announced for later. A date is the
                // first of the month it starts in; the table says "October
                // 2026", never a day.
                $table->decimal('next_percent', 5, 2)->nullable();
                $table->date('next_from')->nullable();
                $table->decimal('future_percent', 5, 2)->nullable();
                $table->date('future_from')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (DB::table('saudization_groups')->exists()) {
            return;
        }

        foreach (self::GROUPS as $i => $group) {
            DB::table('saudization_groups')->insert([
                'name_ar' => $group[0],
                'name_en' => $group[1],
                'job_category' => $group[2],
                'required_percent' => $group[3],
                'next_percent' => $group[4][0] ?? null,
                'next_from' => $group[4][1] ?? null,
                'future_percent' => $group[5][0] ?? null,
                'future_from' => $group[5][1] ?? null,
                'sort_order' => ($i + 1) * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saudization_groups');
    }
};
