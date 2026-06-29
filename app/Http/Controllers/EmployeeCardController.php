<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Setting;
use App\Services\EmployeeCard\VCardService;
use App\Services\EmployeeCard\WalletPassService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EmployeeCardController extends Controller
{
    public function __construct(
        private readonly VCardService      $vcard,
        private readonly WalletPassService $wallet,
    ) {}

    // ─── Public digital card ─────────────────────────────────────────────────

    public function show(string $token): Response|\Illuminate\Contracts\View\View
    {
        $employee = Employee::where('card_token', $token)
            ->where('status', 'active')
            ->with(['identityUser', 'branch', 'department'])
            ->firstOrFail();

        $identity = $employee->identityUser;
        $setting  = Setting::get();

        $data = [
            'name'        => $employee->name,
            'initials'    => $employee->initials(),
            'job_title'   => $employee->job_title ?: $identity?->job_title,
            'department'  => $identity?->department ?: $employee->department?->name,
            'company'     => $setting->company_name ?: 'Samir Group',
            'email'       => $employee->email ?: $identity?->mail ?: $identity?->user_principal_name,
            'phone'       => $identity?->phone_number,
            'mobile'      => $employee->mobile_phone ?: $identity?->mobile_phone,
            'extension'   => $employee->extension_number,
            'branch'      => $employee->branch?->name,
            'city'        => $employee->branch?->city ?: $identity?->city,
            'logo_path'   => $setting->company_logo ? asset('storage/' . $setting->company_logo) : null,
            'card_url'    => url("/card/{$token}"),
            'vcard_url'   => url("/card/{$token}/vcard"),
            'wallet_url'  => url("/card/{$token}/wallet"),
            'wallet_ready'=> $this->wallet->isConfigured(),
            'token'       => $token,
            'employee'    => $employee,
        ];

        return view('public.employee-card', $data);
    }

    // ─── vCard download ──────────────────────────────────────────────────────

    public function vcard(string $token): Response
    {
        $employee = Employee::where('card_token', $token)
            ->where('status', 'active')
            ->with(['identityUser', 'branch', 'department'])
            ->firstOrFail();

        $vcf      = $this->vcard->generate($employee);
        $filename = Str::slug($employee->name) . '.vcf';

        return response($vcf, 200, [
            'Content-Type'        => 'text/vcard; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── Apple Wallet pass download ──────────────────────────────────────────

    public function walletPass(string $token): Response
    {
        $employee = Employee::where('card_token', $token)
            ->where('status', 'active')
            ->with(['identityUser', 'branch'])
            ->firstOrFail();

        if (! $this->wallet->isConfigured()) {
            abort(503, 'Apple Wallet is not configured on this server.');
        }

        $pkpassPath = $this->wallet->generate($employee);
        $filename   = Str::slug($employee->name) . '.pkpass';

        $response = response()->file($pkpassPath, [
            'Content-Type'        => 'application/vnd.apple.pkpass',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);

        // Delete the temp file after response is sent
        register_shutdown_function(fn () => is_file($pkpassPath) && @unlink(dirname($pkpassPath)) && true);
        // Clean temp dir
        $tmpDir = dirname($pkpassPath);
        register_shutdown_function(function () use ($tmpDir) {
            if (is_dir($tmpDir)) {
                array_map('unlink', glob("$tmpDir/*") ?: []);
                @rmdir($tmpDir);
            }
        });

        return $response;
    }

    // ─── Admin: generate / show share modal data ─────────────────────────────

    /**
     * Returns the share card info for the admin panel modal (JSON).
     * Requires authentication — called via fetch from the employee show page.
     */
    public function adminShareData(Employee $employee): \Illuminate\Http\JsonResponse
    {
        // Ensure token exists
        if (! $employee->card_token) {
            $employee->update(['card_token' => Str::uuid()->toString()]);
        }

        $cardUrl = url("/card/{$employee->card_token}");
        $qrSvg   = $this->makeQr($cardUrl);

        return response()->json([
            'card_url'    => $cardUrl,
            'qr_svg'      => $qrSvg,
            'vcard_url'   => url("/card/{$employee->card_token}/vcard"),
            'wallet_url'  => url("/card/{$employee->card_token}/wallet"),
            'wallet_ready'=> $this->wallet->isConfigured(),
        ]);
    }

    /**
     * Regenerate the card token (invalidates all previous share links).
     */
    public function regenerateToken(Employee $employee): \Illuminate\Http\JsonResponse
    {
        $employee->update(['card_token' => Str::uuid()->toString()]);
        $cardUrl = url("/card/{$employee->card_token}");

        return response()->json([
            'card_url'   => $cardUrl,
            'qr_svg'     => $this->makeQr($cardUrl),
            'vcard_url'  => url("/card/{$employee->card_token}/vcard"),
            'wallet_url' => url("/card/{$employee->card_token}/wallet"),
        ]);
    }

    // ─── QR code ─────────────────────────────────────────────────────────────

    private function makeQr(string $url): string
    {
        $options = new QROptions([
            'outputType'    => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel'      => QRCode::ECC_M,
            'imageBase64'   => false,
            'svgDefs'       => '',
        ]);
        return (new QRCode($options))->render($url);
    }
}
