<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\View\View;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        $apiKey  = config('services.hr_api.key', '');
        $baseUrl = rtrim(config('app.url'), '/');

        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Department::orderBy('name')->get(['id', 'name']);

        return view('admin.api-docs.index', compact('apiKey', 'baseUrl', 'branches', 'departments'));
    }
}
