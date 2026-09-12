<?php

namespace Tests\Unit\Rbac;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four tables the RBAC tests need, built directly.
 *
 * Not RefreshDatabase: 18 migrations in this repo issue raw MySQL DDL
 * (`ALTER TABLE … MODIFY COLUMN …`), which SQLite rejects, so the full
 * migration set cannot run on the `:memory:` connection phpunit.xml configures
 * — tests/Feature/Api/HrApiTest.php has the same problem and predates this
 * change. Building just these tables keeps the access-control rules actually
 * covered instead of leaving them untested until that is sorted out.
 */
class RbacTestSchema
{
    public static function create(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 50)->default('viewer');
            $table->string('whatsapp_number')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('dark_mode')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->json('surfaces')->nullable();
            $table->string('landing', 40)->default('noc_admin');
            $table->boolean('is_super')->default(false);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50);
            $table->string('permission', 100);
            $table->unique(['role', 'permission']);
            $table->timestamps();
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('permission', 80);
            $table->string('effect', 10)->default('grant');
            $table->timestamps();

            // Deliberately NOT unique on (user_id, permission) here, so a test
            // can stage the contradictory grant+deny pair and prove deny wins.
            $table->index('user_id');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('model_label', 150)->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_label', 100)->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        foreach (['activity_logs', 'user_permissions', 'role_permissions', 'roles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
