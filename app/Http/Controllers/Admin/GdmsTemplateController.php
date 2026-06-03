<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GdmsTemplate;
use Illuminate\Support\Facades\Artisan;

/**
 * GDMS configuration-template viewer (read-only). GDMS's OpenAPI exposes only
 * template LIST (/v1.0.0/template/group/list) — creating, editing, and assigning
 * templates are GDMS web-console operations, so this controller only lists the
 * cached "group" templates and triggers a refresh.
 */
class GdmsTemplateController extends Controller
{
    public function index()
    {
        $this->authorize('view-phones');

        $templates = GdmsTemplate::orderBy('name')->get();

        return view('admin.gdms.templates.index', compact('templates'));
    }

    public function sync()
    {
        $this->authorize('manage-phones');

        $code = Artisan::call('gdms:sync-templates');
        $out = trim(Artisan::output());

        if ($code !== 0) {
            return back()->with('error', $out ?: 'Template sync failed.');
        }

        return back()->with('success', $out ?: 'Templates synced from GDMS.');
    }
}
