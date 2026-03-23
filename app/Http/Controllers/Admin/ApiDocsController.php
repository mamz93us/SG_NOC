<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrApiKey;
use Illuminate\View\View;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        $baseUrl = rtrim(config('app.url'), '/');

        // DB-managed keys (new system)
        $hrApiKeys = HrApiKey::where('is_active', true)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'key_prefix', 'last_used_at', 'created_at']);

        // Legacy config key — shown only during migration period
        $legacyKey = config('services.hr_api.key', '');

        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Department::orderBy('name')->get(['id', 'name']);

        return view('admin.api-docs.index', compact(
            'hrApiKeys', 'legacyKey', 'baseUrl', 'branches', 'departments'
        ));
    }
}
