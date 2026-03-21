<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPrinterSetupEmailJob;
use App\Models\Employee;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PrinterDeployController extends Controller
{
    /**
     * POST /admin/printer-deploy
     * Send a printer setup link to an employee's email.
     * Placed on the employee show page as a small form.
     */
    public function deploy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
        ]);

        $employee = Employee::with('branch')->findOrFail($data['employee_id']);

        if (empty($employee->email)) {
            return back()->withErrors(['employee_id' => 'This employee has no email address on record.']);
        }

        if (! $employee->branch_id) {
            return back()->withErrors(['employee_id' => 'This employee has no branch assigned.']);
        }

        $token = PrinterDeployToken::create([
            'employee_id'   => $employee->id,
            'branch_id'     => $employee->branch_id,
            'token'         => Str::random(64),
            'expires_at'    => now()->addDays(7),
            'sent_to_email' => $employee->email,
        ]);

        SendPrinterSetupEmailJob::dispatch($token->id)->onQueue('emails');

        return back()->with('success', 'Printer setup link sent to ' . $employee->email);
    }
}
