<?php

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Ai\AiAccess;
use Tests\Unit\Rbac\RbacTestSchema;

uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();

    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();

    foreach ([['super_admin', true], ['viewer', false], ['recruiter', false]] as [$slug, $super]) {
        Role::create([
            'slug' => $slug, 'name' => ucwords(str_replace('_', ' ', $slug)), 'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
            'is_super' => $super, 'is_system' => false, 'sort_order' => 10,
        ]);
    }

    $now = now();
    RolePermission::insert([['role' => 'recruiter', 'permission' => 'use-recruitment-ai', 'created_at' => $now, 'updated_at' => $now]]);

    Role::clearCache();
    RolePermission::clearCache();

    $this->access = new AiAccess;
    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'super_admin']);
});

afterEach(fn () => RbacTestSchema::drop());

it('gives and takes away Recruitment AI with an individual grant, logging both as security events', function () {
    $user = User::create(['name' => 'Rana', 'email' => 'rana@example.com', 'password' => 'x', 'role' => 'viewer']);

    $this->access->grant($user, 'recruitment', $this->admin);

    expect($user->fresh()->hasPermission('use-recruitment-ai'))->toBeTrue()
        ->and(UserPermission::where('user_id', $user->id)->pluck('effect', 'permission')->all())->toBe(['use-recruitment-ai' => 'grant']);

    $this->access->revoke($user, 'recruitment', $this->admin);

    expect($user->fresh()->hasPermission('use-recruitment-ai'))->toBeFalse()
        ->and(UserPermission::where('user_id', $user->id)->count())->toBe(0);

    $logs = ActivityLog::whereIn('action', ['ai_access_granted', 'ai_access_revoked'])->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['ai_access_granted', 'ai_access_revoked'])
        ->and($logs[0]->changes['access'])->toBe(['old' => false, 'new' => true])
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and(config('audit.security_actions'))->toContain('ai_access_granted', 'ai_access_revoked');
});

it('blocks someone who has Recruitment AI through their role, and can give it back', function () {
    $user = User::create(['name' => 'Role Holder', 'email' => 'holder@example.com', 'password' => 'x', 'role' => 'recruiter']);
    expect($user->hasPermission('use-recruitment-ai'))->toBeTrue();

    $this->access->revoke($user, 'recruitment', $this->admin);

    expect($user->fresh()->hasPermission('use-recruitment-ai'))->toBeFalse()
        ->and(UserPermission::where('user_id', $user->id)->value('effect'))->toBe('deny')
        ->and($this->access->holders('recruitment')->firstWhere('user.id', $user->id))->toMatchArray(['through' => 'role', 'blocked' => true]);

    $this->access->grant($user, 'recruitment', $this->admin);

    expect($user->fresh()->hasPermission('use-recruitment-ai'))->toBeTrue()
        ->and(UserPermission::where('user_id', $user->id)->count())->toBe(0);
});

it('lists super admins, individual grants and role holders, and nobody else', function () {
    $granted = User::create(['name' => 'Granted', 'email' => 'granted@example.com', 'password' => 'x', 'role' => 'viewer']);
    $this->access->grant($granted, 'recruitment', $this->admin);
    User::create(['name' => 'Role Holder', 'email' => 'holder@example.com', 'password' => 'x', 'role' => 'recruiter']);
    User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'x', 'role' => 'viewer']);

    $through = $this->access->holders('recruitment')
        ->mapWithKeys(fn (array $row) => [$row['user']->email => $row['through']])
        ->all();

    expect($through)->toBe([
        'admin@example.com' => 'super_admin',
        'granted@example.com' => 'grant',
        'holder@example.com' => 'role',
    ]);
});
