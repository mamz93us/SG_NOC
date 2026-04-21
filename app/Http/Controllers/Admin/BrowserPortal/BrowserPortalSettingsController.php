<?php

namespace App\Http\Controllers\Admin\BrowserPortal;

use App\Http\Controllers\Controller;
use App\Models\BrowserPortalSettings;
use App\Models\BrowserSessionEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BrowserPortalSettingsController extends Controller
{
    public function index(): View
    {
        $settings = BrowserPortalSettings::current();
        return view('admin.browser-portal.settings', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idle_minutes'            => ['required', 'integer', 'min:5', 'max:1440'],
            'max_concurrent_sessions' => ['required', 'integer', 'min:1', 'max:100'],
            'udp_port_range_start'    => ['required', 'integer', 'min:1024', 'max:65535'],
            'udp_port_range_end'      => ['required', 'integer', 'min:1024', 'max:65535', 'gte:udp_port_range_start'],
            'ports_per_session'       => ['required', 'integer', 'min:1', 'max:100'],
            'neko_image'              => ['required', 'string', 'max:255'],
            'desktop_resolution'      => ['required', 'string', 'max:32'],
            'auto_request_control'    => ['sometimes', 'boolean'],
            'hide_neko_branding'      => ['sometimes', 'boolean'],
        ]);

        $data['auto_request_control'] = $request->boolean('auto_request_control');
        $data['hide_neko_branding']   = $request->boolean('hide_neko_branding');

        $settings = BrowserPortalSettings::current();
        $settings->fill($data)->save();   // booted() hook invalidates cache.

        BrowserSessionEvent::create([
            'user_id'    => Auth::id(),
            'event_type' => 'settings_changed',
            'message'    => 'Browser portal settings updated',
            'metadata'   => $data,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Settings saved. New values apply to sessions launched from now on.');
    }
}
