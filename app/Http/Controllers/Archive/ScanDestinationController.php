<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveScanEndpoint;
use App\Models\User;
use App\Services\Backup\SftpgoApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Scan destinations: the addresses and folders the copiers send to.
 *
 * Set up once per machine, then used for years by people who never open this
 * portal. That is the whole value — an invoice reaches the archive by someone
 * pressing Scan, not by anyone uploading a file.
 *
 * Gated by `manage-archive-portal`, because a destination decides whose inbox
 * arriving documents land in. Note what a destination is NOT: it never grants
 * read access to anything. A token in an address lets a machine PUT a document
 * into an inbox, and filing it is still a signed-in action gated by `can_add`.
 * That asymmetry is what makes an unauthenticated scan address acceptable, and
 * nothing here should ever be extended to hand content back.
 *
 * The token is shown exactly once, on creation. It is stored as a hash because it
 * lives in an address printed on a copier's display, and a write credential in
 * the audit log is a write credential in the audit log.
 */
class ScanDestinationController extends Controller
{
    public function index(Request $request): View
    {
        $sftpgo = new SftpgoApiService;

        return view('admin.archive.scan', [
            'endpoints' => ArchiveScanEndpoint::query()
                ->with(['user', 'archive'])
                ->orderBy('type')
                ->orderBy('label')
                ->get(),
            'archives' => Archive::query()
                ->where('mode', Archive::MODE_NATIVE)
                ->readable()
                ->orderBy('name')
                ->get(),
            'mailDomain' => (string) config('archive_portal.scan_mail_domain'),
            'spool' => (string) config('archive_portal.mail_spool'),
            'folderRoot' => (string) config('archive_portal.scan_folder_root'),
            'mailReady' => @is_dir((string) config('archive_portal.mail_spool')),
            'sftpgoReady' => $sftpgo->isConfigured(),
            'types' => ArchiveScanEndpoint::TYPES,
            // Shown once, then gone: the flash is the only time anybody sees it.
            'newToken' => session('archive_scan_token'),
            'newPassword' => session('archive_scan_password'),
        ]);
    }

    /**
     * Create a destination.
     *
     * A folder destination provisions its SFTPGo user BEFORE the row is saved and
     * rolls the remote user back if saving fails — the same order as
     * BackupAccountController, so there is never a half-provisioned account: a
     * login that exists with no row behind it, or a row pointing at a login that
     * was never created.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(ArchiveScanEndpoint::TYPES))],
            'label' => ['required', 'string', 'max:120'],
            // Exactly one destination. Neither would be an inbox nobody can see.
            'goes_to' => ['required', 'string'],
        ]);

        [$userId, $archiveId] = $this->destination($data['goes_to']);

        if ($userId === null && $archiveId === null) {
            return back()->withInput()->with('error', 'Choose who or which archive this destination sends to.');
        }

        [$token, $hash] = ArchiveScanEndpoint::newToken();

        $endpoint = new ArchiveScanEndpoint([
            'type' => $data['type'],
            'user_id' => $userId,
            'archive_id' => $archiveId,
            'label' => $data['label'],
            'token_hash' => $hash,
            'token_hint' => substr($token, 0, 4),
            'enabled' => true,
            'created_by' => $request->user()?->getKey(),
        ]);

        if ($endpoint->isFolder()) {
            return $this->createFolder($endpoint, $token);
        }

        $endpoint->save();

        $this->log('archive_scan_endpoint_created', $endpoint);

        return redirect()
            ->route('admin.archive.scan')
            ->with('status', 'Destination created. Put the address below into the copier — it is shown only now.')
            ->with('archive_scan_token', $endpoint->address($token));
    }

    /**
     * A folder destination: an SFTPGo login the copier signs in as.
     */
    private function createFolder(ArchiveScanEndpoint $endpoint, string $token): RedirectResponse
    {
        $sftpgo = new SftpgoApiService;

        if (! $sftpgo->isConfigured()) {
            return back()->withInput()->with(
                'error',
                'SFTPGo is not configured — set it up under the NOC\'s Settings ▸ SFTPGo first.',
            );
        }

        $endpoint->sftpgo_username = $this->uniqueUsername($endpoint->label);
        $endpoint->home_dir = $endpoint->homeDir();

        // Alphanumeric only: copier keypads and the shell-based transports choke
        // on symbols, and a password nobody can type is a destination nobody uses.
        $password = Str::password(20, letters: true, numbers: true, symbols: false, spaces: false);

        try {
            $sftpgo->createUser($endpoint, $password);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'SFTPGo user creation failed: '.$e->getMessage());
        }

        try {
            $endpoint->save();
        } catch (\Throwable $e) {
            // Roll the remote login back rather than leave one with no row.
            try {
                $sftpgo->deleteUser($endpoint->sftpgoUsername());
            } catch (\Throwable) {
            }

            return back()->withInput()->with('error', 'Saving the destination failed (the SFTPGo login was removed): '.$e->getMessage());
        }

        $this->log('archive_scan_endpoint_created', $endpoint);

        return redirect()
            ->route('admin.archive.scan')
            ->with('status', 'Folder destination created. The password is shown only now.')
            ->with('archive_scan_password', [
                'username' => $endpoint->sftpgoUsername(),
                'password' => $password,
                'folder' => $endpoint->homeDir(),
            ]);
    }

    /** Switch a destination off without losing its folder, history or password. */
    public function toggle(Request $request, ArchiveScanEndpoint $endpoint): RedirectResponse
    {
        $endpoint->forceFill(['enabled' => ! $endpoint->enabled])->save();

        // A folder destination has to be told, or SFTPGo keeps accepting uploads
        // into a folder nothing sweeps.
        if ($endpoint->isFolder() && $endpoint->sftpgo_username) {
            try {
                (new SftpgoApiService)->updateUser($endpoint);
            } catch (\Throwable $e) {
                Log::warning('[archive] SFTPGo status not updated for '.$endpoint->sftpgoUsername().': '.$e->getMessage());

                return back()->with(
                    'error',
                    'Saved here, but SFTPGo did not accept the change — the copier may still be able to upload. '.$e->getMessage(),
                );
            }
        }

        $this->log($endpoint->enabled ? 'archive_scan_endpoint_enabled' : 'archive_scan_endpoint_disabled', $endpoint);

        return back()->with('status', $endpoint->label.($endpoint->enabled ? ' switched on.' : ' switched off.'));
    }

    /**
     * Delete a destination.
     *
     * The SFTPGo login goes with it; anything already swept into an inbox stays,
     * because those are documents and this is only the door they came through.
     */
    public function destroy(Request $request, ArchiveScanEndpoint $endpoint): RedirectResponse
    {
        if ($endpoint->isFolder() && $endpoint->sftpgo_username) {
            try {
                (new SftpgoApiService)->deleteUser($endpoint->sftpgoUsername());
            } catch (\Throwable $e) {
                return back()->with(
                    'error',
                    'The SFTPGo login could not be removed, so nothing was deleted here either — '
                    .'otherwise the copier would keep uploading into a folder nothing reads. '.$e->getMessage(),
                );
            }
        }

        $label = $endpoint->label;

        $this->log('archive_scan_endpoint_deleted', $endpoint);
        $endpoint->delete();

        return back()->with('status', $label.' deleted. Scans already received are untouched.');
    }

    /**
     * Replace the token or password of an existing destination.
     *
     * Needed whenever somebody leaves with a copier's settings written down, and
     * the reason the token is hashed rather than recoverable: rotating is cheap,
     * and a readable credential is not.
     */
    public function rotate(Request $request, ArchiveScanEndpoint $endpoint): RedirectResponse
    {
        if ($endpoint->isFolder()) {
            $password = Str::password(20, letters: true, numbers: true, symbols: false, spaces: false);

            try {
                (new SftpgoApiService)->setPassword($endpoint, $password);
            } catch (\Throwable $e) {
                return back()->with('error', 'SFTPGo refused the new password, so nothing changed: '.$e->getMessage());
            }

            $this->log('archive_scan_endpoint_rotated', $endpoint);

            return back()
                ->with('status', 'New password set. It is shown only now.')
                ->with('archive_scan_password', [
                    'username' => $endpoint->sftpgoUsername(),
                    'password' => $password,
                    'folder' => $endpoint->homeDir(),
                ]);
        }

        [$token, $hash] = ArchiveScanEndpoint::newToken();

        $endpoint->forceFill(['token_hash' => $hash, 'token_hint' => substr($token, 0, 4)])->save();

        $this->log('archive_scan_endpoint_rotated', $endpoint);

        return back()
            ->with('status', 'New address set — the old one stops working now. Update the copier.')
            ->with('archive_scan_token', $endpoint->address($token));
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Resolve the "goes to" choice into exactly one destination.
     *
     * @return array{0:?int, 1:?int} user id, archive id
     */
    private function destination(string $choice): array
    {
        if (str_starts_with($choice, 'archive:')) {
            $archive = Archive::query()
                ->where('mode', Archive::MODE_NATIVE)
                ->readable()
                ->find((int) substr($choice, 8));

            return [null, $archive?->getKey()];
        }

        if (str_starts_with($choice, 'user:')) {
            return [User::find((int) substr($choice, 5))?->getKey(), null];
        }

        return [null, null];
    }

    /**
     * An SFTPGo username nothing else is using.
     *
     * Prefixed so a scan login is recognisable beside the device backup accounts
     * in SFTPGo's own admin, which is a shared namespace.
     */
    private function uniqueUsername(string $label): string
    {
        $base = 'scan-'.(Str::slug($label) ?: 'destination');
        $base = mb_substr($base, 0, 40);
        $name = $base;
        $n = 2;

        while (ArchiveScanEndpoint::where('sftpgo_username', $name)->exists()) {
            $name = $base.'-'.$n++;
        }

        return $name;
    }

    /**
     * Logged by hand as a security action.
     *
     * A destination decides whose inbox arriving documents land in, so creating,
     * disabling or rotating one is exactly the kind of change somebody should be
     * able to look up later. The token itself is never written here.
     */
    private function log(string $action, ArchiveScanEndpoint $endpoint): void
    {
        try {
            ActivityLog::log($action, $endpoint, [
                'label' => $endpoint->label,
                'type' => $endpoint->type,
                'goes_to' => $endpoint->destinationLabel(),
                'sftpgo_username' => $endpoint->sftpgo_username,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[archive] scan destination change not logged: '.$e->getMessage());
        }
    }
}
