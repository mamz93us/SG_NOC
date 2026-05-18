<?php

use App\Models\Branch;
use App\Models\IspConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branchHQ = Branch::create(['id' => 1, 'name' => 'HQ']);
    $this->branchJED = Branch::create(['id' => 2, 'name' => 'JED']);

    IspConnection::create([
        'branch_id' => $this->branchHQ->id,
        'provider' => 'STC',
        'account_number' => 'STC-001',
        'connection_type' => 'fiber',
        'customer_type' => 'business',
        'payment_type' => 'postpaid',
        'billing_day' => 5,
        'package' => '100M Fiber Business',
        'monthly_cost' => 1500,
    ]);

    IspConnection::create([
        'branch_id' => $this->branchHQ->id,
        'provider' => 'Mobily',
        'account_number' => 'MOB-002',
        'connection_type' => '5g',
        'customer_type' => 'home',
        'payment_type' => 'prepaid',
        'billing_day' => 15,
        'monthly_cost' => 400,
    ]);

    IspConnection::create([
        'branch_id' => $this->branchJED->id,
        'provider' => 'STC',
        'account_number' => 'STC-077',
        'connection_type' => 'copper',
        'customer_type' => 'business',
        'payment_type' => 'postpaid',
        'billing_day' => 1,
        'monthly_cost' => 800,
    ]);
});

it('filters report by branch and sums monthly_cost only for that branch', function () {
    $controller = new \App\Http\Controllers\Admin\IspReportController;
    $request = \Illuminate\Http\Request::create('/admin/network/isp-report', 'GET', [
        'branch_id' => $this->branchHQ->id,
    ]);

    $view = $controller->index($request);
    $data = $view->getData();

    expect($data['connections'])->toHaveCount(2);
    expect((float) $data['totalCost'])->toBe(1900.0);
});

it('filters report by provider', function () {
    $controller = new \App\Http\Controllers\Admin\IspReportController;
    $request = \Illuminate\Http\Request::create('/admin/network/isp-report', 'GET', [
        'provider' => 'STC',
    ]);

    $view = $controller->index($request);
    $data = $view->getData();

    expect($data['connections'])->toHaveCount(2);
    expect((float) $data['totalCost'])->toBe(2300.0);
});

it('filters report by account_number', function () {
    $controller = new \App\Http\Controllers\Admin\IspReportController;
    $request = \Illuminate\Http\Request::create('/admin/network/isp-report', 'GET', [
        'account_number' => 'STC-077',
    ]);

    $view = $controller->index($request);
    $data = $view->getData();

    expect($data['connections'])->toHaveCount(1);
    expect((float) $data['totalCost'])->toBe(800.0);
});

it('groups connections by branch when no branch filter is set', function () {
    $controller = new \App\Http\Controllers\Admin\IspReportController;
    $request = \Illuminate\Http\Request::create('/admin/network/isp-report', 'GET', []);

    $view = $controller->index($request);
    $data = $view->getData();

    expect($data['byBranch'])->toHaveCount(2);
    expect((float) $data['totalCost'])->toBe(2700.0);
});
