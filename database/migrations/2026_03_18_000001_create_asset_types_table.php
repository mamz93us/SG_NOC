<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();         // e.g. 'laptop', 'switch', 'phone'
            $table->string('label', 60);                   // e.g. 'Laptop', 'Network Switch'
            $table->string('icon', 60)->default('bi-cpu'); // Bootstrap icon class
            $table->string('badge_class', 60)->default('bg-secondary'); // Bootstrap badge class
            $table->string('category_code', 5);            // 3-letter code for asset code gen, e.g. 'LAP'
            $table->boolean('is_user_equipment')->default(false); // assignable to employees?
            $table->enum('group', ['infrastructure', 'user_equipment', 'other'])->default('other');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        // Change devices.type from ENUM to VARCHAR to support dynamic types
        DB::statement("ALTER TABLE devices MODIFY COLUMN type VARCHAR(30) NOT NULL DEFAULT 'other'");

        // Seed with existing types
        $now = now();
        DB::table('asset_types')->insert([
            // Infrastructure
            ['slug' => 'ucm',      'label' => 'UCM / IPPBX',    'icon' => 'bi-telephone-fill',  'badge_class' => 'bg-primary',          'category_code' => 'PHN', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'switch',   'label' => 'Network Switch',  'icon' => 'bi-hdd-network',     'badge_class' => 'bg-info text-dark',   'category_code' => 'NET', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'router',   'label' => 'Router',          'icon' => 'bi-router-fill',     'badge_class' => 'bg-warning text-dark', 'category_code' => 'RTR', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'firewall', 'label' => 'Firewall',        'icon' => 'bi-shield-lock-fill','badge_class' => 'bg-danger',           'category_code' => 'FWL', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'ap',       'label' => 'Access Point',    'icon' => 'bi-wifi',            'badge_class' => 'bg-success',          'category_code' => 'WAP', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'printer',  'label' => 'Printer',         'icon' => 'bi-printer-fill',    'badge_class' => 'bg-secondary',        'category_code' => 'PRN', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 60, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'server',   'label' => 'Server',          'icon' => 'bi-server',          'badge_class' => 'bg-dark',             'category_code' => 'SRV', 'is_user_equipment' => false, 'group' => 'infrastructure', 'sort_order' => 70, 'created_at' => $now, 'updated_at' => $now],
            // User Equipment
            ['slug' => 'laptop',   'label' => 'Laptop',          'icon' => 'bi-laptop',          'badge_class' => 'bg-primary',          'category_code' => 'LAP', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 80, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'desktop',  'label' => 'Desktop',         'icon' => 'bi-pc-display',      'badge_class' => 'bg-primary',          'category_code' => 'DSK', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 90, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'monitor',  'label' => 'Monitor',         'icon' => 'bi-display',         'badge_class' => 'bg-info text-dark',   'category_code' => 'MON', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 100, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'keyboard', 'label' => 'Keyboard',        'icon' => 'bi-keyboard',        'badge_class' => 'bg-secondary',        'category_code' => 'KBD', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 110, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'mouse',    'label' => 'Mouse',           'icon' => 'bi-mouse',           'badge_class' => 'bg-secondary',        'category_code' => 'MOU', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 120, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'headset',  'label' => 'Headset',         'icon' => 'bi-headset',         'badge_class' => 'bg-secondary',        'category_code' => 'HDT', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 130, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'tablet',   'label' => 'Tablet',          'icon' => 'bi-tablet',          'badge_class' => 'bg-info text-dark',   'category_code' => 'TAB', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 140, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'phone',    'label' => 'IP Phone',        'icon' => 'bi-telephone',       'badge_class' => 'bg-success',          'category_code' => 'PHN', 'is_user_equipment' => true,  'group' => 'user_equipment', 'sort_order' => 150, 'created_at' => $now, 'updated_at' => $now],
            // Other
            ['slug' => 'other',    'label' => 'Other',           'icon' => 'bi-cpu',             'badge_class' => 'bg-secondary',        'category_code' => 'OTH', 'is_user_equipment' => false, 'group' => 'other',          'sort_order' => 999, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
