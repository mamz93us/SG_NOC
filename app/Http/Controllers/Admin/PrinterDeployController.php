<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPrinterSetupEmailJob;
use App\Models\Employee;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrinterDeployController extends Controller
{
    /**
     * POST /admin/printers/{printer}/deploy
     * Send a printer setup email to a specific employee.
     */
    public function deploy(Request $request, Printer $printer): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'email'       => 'required_without:employee_id|nullable|email|max:200',
        ]);

        $employee = isset($data['employee_id']) ? Employee::find($data['employee_id']) : null;
        $email    = $data['email'] ?? $employee?->email;

        if (! $email) {
            return response()->json(['error' => 'No email address provided.'], 422);
        }

        // Build a config snapshot so the setup page has everything it needs
        $config = [
            'printer_name'  => $printer->printer_name,
            'ip_address'    => $printer->ip_address,
            'manufacturer'  => $printer->manufacturer,
            'model'         => $printer->model,
            'share_name'    => preg_replace('/[^A-Za-z0-9_-]/', '', $printer->printer_name),
            'driver_url'    => null,   // filled by admin if needed
            'branch'        => $printer->branch?->name,
            'location'      => $printer->locationLabel(),
        ];

        $token = PrinterDeployToken::generate($printer->id, [
            'employee_id'   => $employee?->id,
            'sent_to_email' => $email,
            'printer_config'=> $config,
        ]);

        SendPrinterSetupEmailJob::dispatch($token->id)->onQueue('emails');

        return response()->json([
            'ok'      => true,
            'message' => "Setup email sent to {$email}.",
        ]);
    }
}
