<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NetworkSwitch;
use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelnetController extends Controller
{
    /** Quick-connect / device-picker page */
    public function index(): \Illuminate\View\View
    {
        $printers = Printer::whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->orderBy('printer_name')
            ->get(['id', 'printer_name', 'ip_address', 'branch_id']);

        $switches = NetworkSwitch::whereNotNull('lan_ip')
            ->where('lan_ip', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'lan_ip', 'model', 'branch_id']);

        return view('admin.telnet.index', compact('printers', 'switches'));
    }

    /**
     * Generate a one-time session token, cache connection details, and
     * redirect to the terminal view.  Credentials never touch the URL.
     */
    public function connect(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'host'     => ['required', 'string', 'max:255'],
            'port'     => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'],
            'label'    => ['nullable', 'string', 'max:150'],
        ]);

        $token = Str::random(40);
        $ttl   = (int) config('telnet.token_ttl', 5);

        Cache::put("telnet_token:{$token}", [
            'host'     => trim($validated['host']),
            'port'     => (int) ($validated['port'] ?? config('telnet.default_port', 23)),
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'user_id'  => $request->user()->id,
        ], now()->addMinutes($ttl));

        return redirect()->route('admin.telnet.terminal', [
            'token' => $token,
            'label' => $validated['label'] ?? $validated['host'],
        ]);
    }

    /** Full-screen terminal page */
    public function terminal(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $token = $request->query('token');
        $label = $request->query('label', 'Terminal');

        if (!$token || !Cache::has("telnet_token:{$token}")) {
            return redirect()->route('admin.telnet.index')
                ->with('error', 'Terminal session expired. Please connect again.');
        }

        $wsUrl = rtrim(config('telnet.ws_url', 'wss://noc.samirgroup.net/ws/telnet'), '/');

        return view('admin.telnet.terminal', [
            'wsUrl' => "{$wsUrl}?token={$token}",
            'label' => $label,
        ]);
    }
}
