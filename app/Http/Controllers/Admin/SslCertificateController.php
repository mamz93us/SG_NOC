<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Models\SslCertificate;
use App\Services\Dns\AcmeService;
use App\Services\Dns\CertificateExportService;
use App\Services\Dns\GoDaddyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SslCertificateController extends Controller
{
    public function index(DnsAccount $account, string $domain)
    {
        $certs = SslCertificate::where('account_id', $account->id)
            ->where('domain', $domain)
            ->orderBy('fqdn')
            ->get();

        return view('admin.dns.certificates', compact('account', 'domain', 'certs'));
    }

    public function show(DnsAccount $account, string $domain, SslCertificate $cert)
    {
        // Return cert details WITHOUT private key/certificate PEM
        return response()->json([
            'id'             => $cert->id,
            'fqdn'           => $cert->fqdn,
            'domain'         => $cert->domain,
            'issuer'         => $cert->issuerLabel(),
            'status'         => $cert->status,
            'expiry_status'  => $cert->expiryStatus(),
            'issued_at'      => $cert->issued_at?->toDateString(),
            'expires_at'     => $cert->expires_at?->toDateString(),
            'days_until_expiry' => $cert->daysUntilExpiry(),
            'auto_renew'     => $cert->auto_renew,
            'failure_reason' => $cert->failure_reason,
        ]);
    }

    public function store(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'fqdn'        => 'required|string|max:255',
            'auto_renew'  => 'nullable|boolean',
        ]);

        // Create pending record
        $cert = SslCertificate::updateOrCreate(
            ['account_id' => $account->id, 'fqdn' => $validated['fqdn']],
            [
                'domain'         => $domain,
                'issuer'         => 'letsencrypt',
                'status'         => 'pending',
                'challenge_type' => 'dns01',
                'auto_renew'     => $request->boolean('auto_renew', true),
                'failure_reason' => null,
                'created_by'     => Auth::id(),
            ]
        );

        // Dispatch issuance job
        dispatch(new \App\Jobs\IssueSslCertificateJob($account, $validated['fqdn'], $domain, Auth::user()));

        return response()->json([
            'success' => true,
            'message' => 'Certificate issuance queued.',
            'cert_id' => $cert->id,
            'status'  => $cert->status,
        ]);
    }

    public function renew(DnsAccount $account, string $domain, SslCertificate $cert)
    {
        $godaddy = new GoDaddyService($account);
        $acme    = new AcmeService($godaddy);

        try {
            $renewed = $acme->renewCertificate($cert, $account);
            return response()->json([
                'success'    => true,
                'message'    => "Certificate renewed. New expiry: {$renewed->expires_at?->toDateString()}",
                'expires_at' => $renewed->expires_at?->toDateString(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function revoke(DnsAccount $account, string $domain, SslCertificate $cert)
    {
        $godaddy = new GoDaddyService($account);
        $acme    = new AcmeService($godaddy);

        try {
            $acme->revokeCertificate($cert);
            return response()->json(['success' => true, 'message' => 'Certificate revoked.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(DnsAccount $account, string $domain, SslCertificate $cert)
    {
        $fqdn = $cert->fqdn;
        $cert->delete();

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'certificate.deleted',
            'changes'    => ['fqdn' => $fqdn],
            'user_id'    => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => "Certificate for {$fqdn} deleted."]);
    }

    public function export(Request $request, DnsAccount $account, string $domain, SslCertificate $cert)
    {
        $validated = $request->validate([
            'format'   => 'required|in:pem,cer,key,p12,bundle',
            'password' => 'nullable|string|max:255',
        ]);

        if (!$cert->certificate) {
            return response()->json(['success' => false, 'message' => 'No certificate data available.'], 422);
        }

        // Log the export (always, especially for private key)
        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'certificate.exported',
            'changes'    => ['fqdn' => $cert->fqdn, 'format' => $validated['format'], 'ip' => $request->ip()],
            'user_id'    => Auth::id(),
        ]);

        $exporter = new CertificateExportService();

        try {
            $result = match ($validated['format']) {
                'pem'    => $exporter->exportPem($cert),
                'cer'    => $exporter->exportCer($cert),
                'key'    => $exporter->exportKey($cert),
                'p12'    => $exporter->exportP12($cert, $validated['password'] ?? ''),
                'bundle' => $exporter->exportBundle($cert, $validated['password'] ?? ''),
            };

            if ($validated['format'] === 'bundle') {
                return response()->download($result['path'], $result['filename'], [
                    'Content-Type' => $result['mime'],
                ])->deleteFileAfterSend();
            }

            return response($result['content'], 200, [
                'Content-Type'        => $result['mime'],
                'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
