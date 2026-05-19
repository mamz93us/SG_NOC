<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_icons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();          // slug (used as data-icon key)
            $table->string('label', 100);                  // human display ("Star", "Bell")
            $table->text('svg_path');                      // path "d" attribute (24x24 viewBox)
            $table->string('default_color', 16)->default('#dc3545');
            $table->unsignedInteger('default_size')->default(48);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_marketing_fonts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);                  // "Cairo (Arabic)"
            $table->string('family', 191);                 // CSS family string: "'Cairo', sans-serif"
            $table->string('source', 20)->default('google'); // google | custom
            $table->string('url', 500)->nullable();        // stylesheet URL (Google Fonts CSS link)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed the 13 SAMIR icons that were hardcoded in the template editor so
        // marketing users don't lose access to them.
        $now = now();
        $seed = [
            ['star',      'Star',       'M12 2 L15 9 L22 9 L17 14 L19 21 L12 17 L5 21 L7 14 L2 9 L9 9 Z'],
            ['envelope',  'Envelope',   'M2 4 H22 V20 H2 Z M2 4 L12 13 L22 4'],
            ['check',     'Check',      'M4 12 L10 18 L20 6'],
            ['phone',     'Phone',      'M3 5 C3 4 4 3 5 3 H7 L9 8 L7 10 C8 13 11 16 14 17 L16 15 L21 17 V19 C21 20 20 21 19 21 C10 21 3 14 3 5 Z'],
            ['globe',     'Globe',      'M12 2 A10 10 0 1 0 12 22 A10 10 0 1 0 12 2 Z M2 12 H22 M12 2 C8 8 8 16 12 22 M12 2 C16 8 16 16 12 22'],
            ['shield',    'Shield',     'M12 2 L4 6 V12 C4 17 8 21 12 22 C16 21 20 17 20 12 V6 Z'],
            ['lightning', 'Lightning',  'M13 2 L4 14 H11 L9 22 L20 10 H13 Z'],
            ['heart',     'Heart',      'M12 21 C12 21 3 14 3 8 A5 5 0 0 1 12 5 A5 5 0 0 1 21 8 C21 14 12 21 12 21 Z'],
            ['gear',      'Gear',       'M12 8 A4 4 0 1 0 12 16 A4 4 0 1 0 12 8 Z M19 12 L21 11 L20 8 L17 9 L15 7 L16 4 L12 3 L11 6 L9 7 L6 5 L4 8 L6 10 L5 12 L3 14 L5 16 L7 15 L9 17 L8 20 L12 21 L13 18 L15 17 L18 19 L20 16 L18 14 L19 12 Z'],
            ['bell',      'Bell',       'M12 3 A6 6 0 0 0 6 9 V14 L4 17 H20 L18 14 V9 A6 6 0 0 0 12 3 Z M10 19 A2 2 0 0 0 14 19'],
            ['trophy',    'Trophy',     'M8 21 H16 M12 17 V21 M5 4 H19 V8 A5 5 0 0 1 14 13 H10 A5 5 0 0 1 5 8 Z M5 6 H2 V8 A3 3 0 0 0 5 11 M19 6 H22 V8 A3 3 0 0 0 19 11'],
            ['crown',     'Crown',      'M3 9 L7 12 L12 6 L17 12 L21 9 L19 19 H5 Z'],
            ['calendar',  'Calendar',   'M3 6 H21 V20 H3 Z M3 10 H21 M8 3 V8 M16 3 V8'],
        ];
        $rows = [];
        foreach ($seed as $i => [$name, $label, $path]) {
            $rows[] = [
                'name'          => $name,
                'label'         => $label,
                'svg_path'      => $path,
                'default_color' => '#dc3545',
                'default_size'  => 48,
                'sort_order'    => $i * 10,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }
        DB::table('email_marketing_icons')->insert($rows);

        // Seed two sensible default fonts (Cairo for Arabic + Inter for Latin)
        DB::table('email_marketing_fonts')->insert([
            [
                'label'      => 'Inter (recommended)',
                'family'     => "'Inter', sans-serif",
                'source'     => 'google',
                'url'        => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
                'sort_order' => 10,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'label'      => 'Cairo (Arabic)',
                'family'     => "'Cairo', sans-serif",
                'source'     => 'google',
                'url'        => 'https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap',
                'sort_order' => 20,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_marketing_fonts');
        Schema::dropIfExists('email_marketing_icons');
    }
};
