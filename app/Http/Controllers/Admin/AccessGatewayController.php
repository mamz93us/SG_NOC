<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgwAllowlist;
use App\Models\AgwAudit;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * NOC-side management for the Access Gateway (noc-agw) fronting the legacy
 * IIS app on arcmate.samirgroup.net:
 *   - edit the upstream app URL + IP-ACL toggle (read live by the gateway),
 *   - manage the IP allowlist (dynamic branch IPs + manual CIDRs),
 *   - view the request audit trail.
 *
 * The gateway itself is a separate FastAPI service; this controller only
 * reads/writes the shared agw_* tables and the settings singleton.
 */
class AccessGatewayController extends Controller
{
    /** Allowlist manager + gateway settings. */
    public function index()
    {
        $this->authorize('manage-agw-allowlist');

        $settings = Setting::get();
        $dynamic = AgwAllowlist::dynamic()->orderBy('branch')->get();
        $manual = AgwAllowlist::manual()->orderBy('cidr')->get();

        return view('admin.agw.allowlist', compact('settings', 'dynamic', 'manual'));
    }

    /** Persist the upstream URL + ACL toggle the gateway reads. */
    public function updateSettings(Request $request)
    {
        $this->authorize('manage-agw-settings');

        $validated = $request->validate([
            'agw_backend_url' => ['nullable', 'url', 'max:255'],
            'agw_enforce_ip_acl' => ['boolean'],
        ]);

        $settings = Setting::get();
        $before = [
            'agw_backend_url' => $settings->agw_backend_url,
            'agw_enforce_ip_acl' => (bool) $settings->agw_enforce_ip_acl,
        ];

        $settings->agw_backend_url = $validated['agw_backend_url'] ?? null;
        $settings->agw_enforce_ip_acl = $request->boolean('agw_enforce_ip_acl');
        $settings->save();

        ActivityLog::create([
            'model_type' => 'Setting',
            'model_id' => 1,
            'action' => 'agw_settings_updated',
            'changes' => [
                'before' => $before,
                'after' => [
                    'agw_backend_url' => $settings->agw_backend_url,
                    'agw_enforce_ip_acl' => (bool) $settings->agw_enforce_ip_acl,
                ],
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Access Gateway settings saved. The gateway picks up changes within its refresh window.');
    }

    /** Add a fixed (manual) CIDR that the sync will never overwrite. */
    public function storeManual(Request $request)
    {
        $this->authorize('manage-agw-allowlist');

        $validated = $request->validate([
            'cidr' => ['required', 'string', 'max:43'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $cidr = $this->normalizeCidr($validated['cidr']);
        if ($cidr === null) {
            return back()->withErrors(['cidr' => 'Enter a valid IPv4/IPv6 address or CIDR, e.g. 197.1.2.3 or 197.1.2.0/24.'])->withInput();
        }

        if (AgwAllowlist::where('cidr', $cidr)->exists()) {
            return back()->withErrors(['cidr' => "\"{$cidr}\" is already in the allowlist."])->withInput();
        }

        $created = AgwAllowlist::create([
            'cidr' => $cidr,
            'branch' => null,
            'source' => 'manual',
            'active' => true,
            'note' => $validated['note'] ?? null,
        ]);

        ActivityLog::create([
            'model_type' => AgwAllowlist::class,
            'model_id' => $created->id,
            'action' => 'agw_allowlist_created',
            'changes' => $created->toArray(),
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Allowlist entry \"{$cidr}\" added.");
    }

    /** Toggle an entry active/inactive (both dynamic and manual). */
    public function toggle(AgwAllowlist $entry)
    {
        $this->authorize('manage-agw-allowlist');

        $entry->update(['active' => ! $entry->active]);

        ActivityLog::create([
            'model_type' => AgwAllowlist::class,
            'model_id' => $entry->id,
            'action' => 'agw_allowlist_toggled',
            'changes' => ['cidr' => $entry->cidr, 'active' => $entry->active],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "\"{$entry->cidr}\" is now ".($entry->active ? 'active' : 'inactive').'.');
    }

    /** Delete a manual entry. Dynamic rows are owned by the sync, not deletable here. */
    public function destroyManual(AgwAllowlist $entry)
    {
        $this->authorize('manage-agw-allowlist');

        if ($entry->source !== 'manual') {
            return back()->withErrors(['cidr' => 'Dynamic (branch-synced) entries cannot be deleted here — disable the branch agent or toggle the entry off instead.']);
        }

        $cidr = $entry->cidr;
        $snapshot = $entry->toArray();
        $entry->delete();

        ActivityLog::create([
            'model_type' => AgwAllowlist::class,
            'model_id' => $entry->id,
            'action' => 'agw_allowlist_deleted',
            'changes' => $snapshot,
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Allowlist entry \"{$cidr}\" removed.");
    }

    /** Trigger an immediate branch-IP sync. */
    public function syncNow()
    {
        $this->authorize('manage-agw-allowlist');

        Artisan::call('agw:sync-allowlist');
        $output = trim(Artisan::output());

        return back()->with('success', 'Allowlist synced from branch WAN IPs. '.$output);
    }

    /** Read-only audit trail with filters. */
    public function audit(Request $request)
    {
        $this->authorize('view-agw-audit');

        $query = AgwAudit::query()->orderByDesc('ts');

        if ($ip = trim((string) $request->query('ip'))) {
            $query->where('client_ip', 'like', "%{$ip}%");
        }
        if ($decision = $request->query('decision')) {
            if (in_array($decision, ['allow', 'deny_ip', 'deny_auth'], true)) {
                $query->where('decision', $decision);
            }
        }
        if ($from = $request->query('from')) {
            $query->where('ts', '>=', $from.' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('ts', '<=', $to.' 23:59:59');
        }

        $events = $query->paginate(50)->withQueryString();

        return view('admin.agw.audit', compact('events'));
    }

    /**
     * Normalize a user-entered IP/CIDR to canonical `address/prefix` form.
     * Bare addresses become /32 (IPv4) or /128 (IPv6). Returns null if invalid.
     */
    private function normalizeCidr(string $value): ?string
    {
        $value = trim($value);

        if (! str_contains($value, '/')) {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $value.'/32';
            }
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return strtolower($value).'/128';
            }

            return null;
        }

        [$addr, $prefix] = explode('/', $value, 2);
        if (! ctype_digit($prefix)) {
            return null;
        }
        $prefix = (int) $prefix;

        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $prefix >= 0 && $prefix <= 32 ? "{$addr}/{$prefix}" : null;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefix >= 0 && $prefix <= 128 ? strtolower($addr)."/{$prefix}" : null;
        }

        return null;
    }
}
