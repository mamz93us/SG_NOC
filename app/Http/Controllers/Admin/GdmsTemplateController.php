<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GdmsTemplate;
use App\Services\GdmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * GDMS configuration-template manager: list cached templates, edit their
 * parameters (pushed back to GDMS), and assign a template to devices by MAC.
 */
class GdmsTemplateController extends Controller
{
    public function __construct(private GdmsService $gdms) {}

    public function index()
    {
        $this->authorize('view-phones');

        $templates = GdmsTemplate::orderBy('type')->orderBy('name')->get();

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

    public function edit(GdmsTemplate $template)
    {
        $this->authorize('manage-phones');

        // Try a live refresh of the parameter set; fall back to the cached copy.
        $params = $template->raw['params'] ?? [];
        $liveError = null;
        try {
            $detail = $this->gdms->getTemplate($template->gdms_template_id);
            $params = $detail['data']['params'] ?? $detail['params'] ?? $params;
        } catch (\Throwable $e) {
            $liveError = $e->getMessage();
        }

        return view('admin.gdms.templates.edit', compact('template', 'params', 'liveError'));
    }

    public function update(Request $request, GdmsTemplate $template)
    {
        $this->authorize('manage-phones');

        $validated = $request->validate(['params' => ['required', 'string']]);
        $params = $this->parseParams($validated['params']);

        if (empty($params)) {
            return back()->with('error', 'No valid KEY=VALUE parameters were provided.');
        }

        try {
            $this->gdms->updateTemplate($template->gdms_template_id, $params);
        } catch (\Throwable $e) {
            return back()->with('error', 'Template update failed: '.$e->getMessage());
        }

        $template->update([
            'raw' => array_merge($template->raw ?? [], ['params' => $params]),
            'synced_at' => now(),
        ]);

        ActivityLog::log('GDMS template updated', ['template' => $template->gdms_template_id, 'keys' => array_keys($params)]);

        return redirect()->route('admin.gdms.templates.index')->with('success', 'Template updated and pushed to GDMS.');
    }

    public function assign(Request $request, GdmsTemplate $template)
    {
        $this->authorize('manage-phones');

        $validated = $request->validate(['macs' => ['required', 'string']]);
        $macs = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $validated['macs']))));

        if (empty($macs)) {
            return back()->with('error', 'No MAC addresses provided.');
        }

        try {
            $this->gdms->assignTemplate($template->gdms_template_id, $macs);
        } catch (\Throwable $e) {
            return back()->with('error', 'Template assign failed: '.$e->getMessage());
        }

        ActivityLog::log('GDMS template assigned', ['template' => $template->gdms_template_id, 'count' => count($macs)]);

        return back()->with('success', 'Template assigned to '.count($macs).' device(s).');
    }

    /**
     * Parse a textarea of newline-separated KEY=VALUE pairs into a param map.
     */
    private function parseParams(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim($v);
            }
        }

        return $out;
    }
}
