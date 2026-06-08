<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BackupAccount;
use App\Models\MonitoredHost;
use App\Models\NetworkSwitch;
use App\Models\Setting;
use App\Models\SophosFirewall;
use App\Models\UcmServer;
use App\Services\Backup\SftpgoApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class BackupAccountController extends Controller
{
    /** Device models a backup account can be linked to (display group => class). */
    private const LINKABLES = [
        'Sophos Firewalls' => SophosFirewall::class,
        'UCM Servers' => UcmServer::class,
        'Switches' => NetworkSwitch::class,
        'Monitored Hosts' => MonitoredHost::class,
    ];

    public function index(Request $request)
    {
        $query = BackupAccount::query()->with('device');

        if ($request->filled('q')) {
            $term = $request->q;
            $query->where(function ($w) use ($term) {
                $w->where('sftpgo_username', 'like', "%{$term}%")
                    ->orWhere('label', 'like', "%{$term}%");
            });
        }

        match ($request->input('status')) {
            'overdue' => $query->overdue(),
            'active' => $query->where('is_active', true),
            'disabled' => $query->where('is_active', false),
            default => null,
        };

        $accounts = $query->orderByDesc('is_active')->orderBy('sftpgo_username')
            ->paginate(50)->withQueryString();

        return view('admin.backups.index', compact('accounts'));
    }

    public function create()
    {
        return view('admin.backups.form', [
            'account' => new BackupAccount(['expected_frequency' => 'daily', 'protocols' => ['SFTP'], 'is_active' => true]),
            'linkables' => $this->linkOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateForm($request);

        $sftpgo = new SftpgoApiService;
        if (! $sftpgo->isConfigured()) {
            return back()->withInput()->with('error', 'SFTPGo is not configured — set it up under Settings → SFTPGo first.');
        }

        [$deviceType, $deviceId] = $this->parseLink($request->input('device_link'));

        $account = new BackupAccount([
            'device_type' => $deviceType,
            'device_id' => $deviceId,
            'label' => $data['label'] ?? null,
            'protocols' => $data['protocols'],
            'quota_mb' => $data['quota_mb'] ?? (Setting::get()->sftpgo_default_quota_mb ?: null),
            'expected_frequency' => $data['expected_frequency'],
            'grace_minutes' => $data['grace_minutes'] ?? 0,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);
        $account->sftpgo_username = $this->uniqueUsername($account->deviceLabel());
        $account->home_dir = $account->homeDir();

        // Alphanumeric only — no symbols/spaces. SFTPGo + WHM/cPanel and the
        // shell-based backup transports reject `$ & | ; > , ' " ( ) and spaces.
        $password = Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);
        $account->password = $password;

        // Provision the SFTPGo user FIRST; only persist the row if that succeeds.
        try {
            $sftpgo->createUser($account, $password);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'SFTPGo user creation failed: '.$e->getMessage());
        }

        try {
            $account->save();
        } catch (\Throwable $e) {
            // Roll the remote user back so we never leave a half-provisioned account.
            try {
                $sftpgo->deleteUser($account->sftpgo_username);
            } catch (\Throwable) {
            }

            return back()->withInput()->with('error', 'Saving the account failed (SFTPGo user rolled back): '.$e->getMessage());
        }

        return redirect()->route('admin.backups.show', $account)
            ->with('success', 'Backup account created.')
            ->with('new_password', $password);
    }

    public function show(BackupAccount $backupAccount)
    {
        $backupAccount->load('device');

        return view('admin.backups.show', [
            'account' => $backupAccount,
            'recent' => $backupAccount->backups()->limit(20)->get(),
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: $backupAccount->sftpgo_username,
        ]);
    }

    public function edit(BackupAccount $backupAccount)
    {
        return view('admin.backups.form', [
            'account' => $backupAccount,
            'linkables' => $this->linkOptions(),
        ]);
    }

    public function update(Request $request, BackupAccount $backupAccount)
    {
        $data = $this->validateForm($request);
        [$deviceType, $deviceId] = $this->parseLink($request->input('device_link'));

        // sftpgo_username is immutable — it's the folder/webhook join key.
        $backupAccount->fill([
            'device_type' => $deviceType,
            'device_id' => $deviceId,
            'label' => $data['label'] ?? null,
            'protocols' => $data['protocols'],
            'quota_mb' => $data['quota_mb'] ?? null,
            'expected_frequency' => $data['expected_frequency'],
            'grace_minutes' => $data['grace_minutes'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ]);

        try {
            (new SftpgoApiService)->updateUser($backupAccount);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'SFTPGo update failed: '.$e->getMessage());
        }

        $backupAccount->save();

        return redirect()->route('admin.backups.show', $backupAccount)->with('success', 'Backup account updated.');
    }

    public function reveal(BackupAccount $backupAccount)
    {
        ActivityLog::create([
            'model_type' => 'BackupAccount', 'model_id' => $backupAccount->id,
            'action' => 'password_revealed', 'changes' => ['sftpgo_username' => $backupAccount->sftpgo_username],
            'user_id' => Auth::id(),
        ]);

        return response()->json(['password' => $backupAccount->password]);
    }

    public function rotate(BackupAccount $backupAccount)
    {
        // Alphanumeric only — no symbols/spaces. SFTPGo + WHM/cPanel and the
        // shell-based backup transports reject `$ & | ; > , ' " ( ) and spaces.
        $password = Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);

        try {
            (new SftpgoApiService)->setPassword($backupAccount, $password);
        } catch (\Throwable $e) {
            return back()->with('error', 'SFTPGo password rotation failed: '.$e->getMessage());
        }

        $backupAccount->password = $password;
        $backupAccount->save();

        return redirect()->route('admin.backups.show', $backupAccount)
            ->with('success', 'Password rotated.')
            ->with('new_password', $password);
    }

    /** Default delete = disable (keep history). Hard delete is the separate purge route. */
    public function destroy(BackupAccount $backupAccount)
    {
        $backupAccount->is_active = false;

        try {
            (new SftpgoApiService)->updateUser($backupAccount); // status -> 0 (push refused)
        } catch (\Throwable $e) {
            return back()->with('error', 'SFTPGo disable failed: '.$e->getMessage());
        }

        $backupAccount->save();

        return redirect()->route('admin.backups.index')->with('success', 'Backup account disabled (history kept).');
    }

    public function purge(BackupAccount $backupAccount)
    {
        try {
            (new SftpgoApiService)->deleteUser($backupAccount->sftpgo_username);
        } catch (\Throwable $e) {
            return back()->with('error', 'SFTPGo user deletion failed: '.$e->getMessage());
        }

        $backupAccount->delete();

        return redirect()->route('admin.backups.index')->with('success', 'Backup account permanently deleted.');
    }

    // ─── helpers ──────────────────────────────────────────────────

    private function validateForm(Request $request): array
    {
        return $request->validate([
            'label' => 'nullable|string|max:150',
            'device_link' => 'nullable|string|max:255',
            'protocols' => 'required|array|min:1',
            'protocols.*' => 'in:SFTP,FTP',
            'expected_frequency' => 'required|in:daily,weekly,monthly,manual',
            'grace_minutes' => 'nullable|integer|min:0|max:100000',
            'quota_mb' => 'nullable|integer|min:0',
        ]);
    }

    /** Parse the combined "Class:id" device-link select value back into morph parts. */
    private function parseLink(?string $link): array
    {
        if (! $link || ! str_contains($link, ':')) {
            return [null, null];
        }
        $pos = strrpos($link, ':');
        $class = substr($link, 0, $pos);
        $id = (int) substr($link, $pos + 1);
        $valid = in_array($class, array_values(self::LINKABLES), true);

        return ($valid && $id > 0) ? [$class, $id] : [null, null];
    }

    /** Grouped device options for the link <select> (value = "Class:id"). */
    private function linkOptions(): array
    {
        $out = [];
        foreach (self::LINKABLES as $group => $class) {
            if (! class_exists($class)) {
                continue;
            }
            $out[$group] = $class::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($m) => ['value' => $class.':'.$m->id, 'label' => $m->name])
                ->all();
        }

        return $out;
    }

    private function uniqueUsername(string $seed): string
    {
        // Only letters/numbers/underscores: SFTPGo's web admin and WHM/cPanel
        // destination forms reject hyphens in usernames ("use letters, numbers,
        // underscores, dots and @ only"). Str::slug's default '-' separator would
        // produce names those forms refuse, so use '_' here and for the suffix.
        $base = rtrim(Str::limit(Str::slug($seed, '_') ?: 'device', 24, ''), '_');
        do {
            $username = $base.'_'.Str::lower(Str::random(4));
        } while (BackupAccount::where('sftpgo_username', $username)->exists());

        return $username;
    }
}
