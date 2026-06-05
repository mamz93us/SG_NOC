<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupAccount;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives SFTPGo's "upload" event-manager webhook — fired on every file a device
 * pushes — and stamps the matching BackupAccount as "received" in real time. The
 * sftp-backups:sweep command remains the source of truth for the Azure archive and
 * the sftp_backups rows; this just gives instant per-device "a backup arrived"
 * monitoring.
 *
 * Auth is a shared secret in the X-Backup-Secret header (no user session), exactly
 * like the Graylog webhook. The route is CSRF-excepted in bootstrap/app.php.
 */
class BackupUploadWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = $this->secret();
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Backup-Secret', ''))) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $username = trim((string) $request->input('username'));
        if ($username === '') {
            return response()->json(['ok' => false, 'error' => 'missing username'], 422);
        }

        $account = BackupAccount::where('sftpgo_username', $username)->first();
        if (! $account) {
            // Unknown account — don't error SFTPGo's action queue; just log it.
            Log::info('Backup upload webhook: unknown account', ['username' => $username]);

            return response()->json(['ok' => true, 'matched' => false]);
        }

        // saveQuietly avoids a per-upload ActivityLog entry from the observer.
        $account->forceFill([
            'last_received_at' => now(),
            'last_status' => BackupAccount::STATUS_RECEIVED,
        ])->saveQuietly();

        return response()->json(['ok' => true, 'matched' => true]);
    }

    /** Prefer the Settings value (admin-managed); fall back to env. */
    private function secret(): string
    {
        try {
            $fromSettings = (string) (Setting::get()->sftpgo_webhook_secret ?? '');
        } catch (\Throwable) {
            $fromSettings = '';
        }

        return $fromSettings !== '' ? $fromSettings : (string) config('services.sftpgo.webhook_secret', '');
    }
}
