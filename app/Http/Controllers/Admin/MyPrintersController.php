<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MyPrintersController extends Controller
{
    /**
     * GET /admin/my-printers
     * Shows all printers for the logged-in user's branch.
     * Resolves the user's branch by matching their email to an Employee record.
     */
    public function index(): View
    {
        $user     = Auth::user();
        $employee = Employee::with('branch')
            ->where('email', $user->email)
            ->first();

        $branch   = $employee?->branch;
        $printers = collect();
        $token    = null;

        if ($employee) {
            // Branch printers
            $branchPrinters = $branch
                ? Printer::where('branch_id', $branch->id)->orderBy('printer_name')->get()
                : collect();

            // Manually assigned printers (from any branch)
            $assignedPrinters = $employee->assignedPrinters()->orderBy('printer_name')->get();

            // Merge, deduplicate by ID, keep branch printers first
            $printers = $branchPrinters->merge($assignedPrinters)->unique('id')->values();

            // Get or create a valid deploy token for this session
            // so the user can download install scripts directly
            $token = PrinterDeployToken::where('employee_id', $employee->id)
                ->valid()
                ->latest()
                ->first();

            if (! $token && $printers->isNotEmpty()) {
                $token = PrinterDeployToken::create([
                    'employee_id'   => $employee->id,
                    'branch_id'     => $branch?->id,
                    'token'         => Str::random(64),
                    'expires_at'    => now()->addDays(30),
                    'sent_to_email' => $user->email,
                ]);
            }
        }

        return view('admin.my-printers.index', compact('employee', 'branch', 'printers', 'token', 'user'));
    }
}
