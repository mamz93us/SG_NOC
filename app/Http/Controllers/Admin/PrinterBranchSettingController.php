<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PrinterAlertMail;
use App\Models\Branch;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\PrinterAlertRecipient;
use App\Models\PrinterBranchSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PrinterBranchSettingController extends Controller
{
    public function index()
    {
        // Show every branch with its current setting (or null) and recipient count
        $branches = Branch::orderBy('name')
            ->with(['printerSetting', 'printerAlertRecipients' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return view('admin.printers.branch-settings.index', compact('branches'));
    }

    public function edit(Branch $branch)
    {
        $setting = PrinterBranchSetting::firstOrNew(['branch_id' => $branch->id]);
        $recipients = PrinterAlertRecipient::where('branch_id', $branch->id)
            ->with('user:id,name,email')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.printers.branch-settings.edit', compact('branch', 'setting', 'recipients', 'users'));
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'manager_email'            => 'nullable|email|max:190',
            'manager_name'             => 'nullable|string|max:190',
            'alerts_enabled'           => 'nullable|boolean',
            'toner_warning_threshold'  => 'nullable|integer|min:1|max:100',
            'toner_critical_threshold' => 'nullable|integer|min:1|max:100',
            'waste_critical_threshold' => 'nullable|integer|min:1|max:100',
            'notes'                    => 'nullable|string|max:2000',
        ]);
        $data['alerts_enabled'] = $request->boolean('alerts_enabled');

        PrinterBranchSetting::updateOrCreate(
            ['branch_id' => $branch->id],
            $data
        );

        return redirect()
            ->route('admin.printers.branch.edit', $branch)
            ->with('success', "Printer alert settings saved for \"{$branch->name}\".");
    }

    public function addRecipient(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'email'   => 'nullable|email|max:190',
            'name'    => 'nullable|string|max:190',
        ]);

        if (empty($data['user_id']) && empty($data['email'])) {
            return back()->withErrors(['email' => 'Pick a system user OR enter an email address.'])->withInput();
        }

        PrinterAlertRecipient::create([
            'branch_id' => $branch->id,
            'user_id'   => $data['user_id'] ?? null,
            'email'     => $data['email'] ?? null,
            'name'      => $data['name']  ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Recipient added.');
    }

    public function deleteRecipient(PrinterAlertRecipient $recipient)
    {
        $branchId = $recipient->branch_id;
        $recipient->delete();
        $branch = Branch::find($branchId);
        return redirect()
            ->route('admin.printers.branch.edit', $branch)
            ->with('success', 'Recipient removed.');
    }

    public function toggleRecipient(PrinterAlertRecipient $recipient)
    {
        $recipient->is_active = ! $recipient->is_active;
        $recipient->save();
        $branch = Branch::find($recipient->branch_id);
        return redirect()
            ->route('admin.printers.branch.edit', $branch)
            ->with('success', $recipient->is_active ? 'Recipient enabled.' : 'Recipient disabled.');
    }

    /**
     * Send a synthetic test email to verify routing works for this branch.
     */
    public function test(Branch $branch)
    {
        $setting = PrinterBranchSetting::with('activeRecipients.user')->firstWhere('branch_id', $branch->id);

        if (! $setting || $setting->activeRecipients->isEmpty()) {
            return back()->with('error', 'No active recipients configured for this branch.');
        }

        // Pick any printer in this branch (or build a stub) for the preview.
        $printer = Printer::where('branch_id', $branch->id)->with('branch', 'device')->first()
                  ?? new Printer([
                        'printer_name' => '(test printer)',
                        'manufacturer' => 'Test',
                        'model'        => 'N/A',
                        'ip_address'   => '0.0.0.0',
                  ]);
        if (! $printer->id) {
            $printer->branch = $branch;
        }

        $event = new NocEvent([
            'severity'    => 'warning',
            'module'      => 'assets',
            'entity_type' => 'printer',
            'entity_id'   => 'printer_test',
            'source_type' => 'printer',
            'source_id'   => $printer->id,
            'title'       => 'Printer alert test',
            'message'     => 'This is a test email confirming printer-alert routing for this branch.',
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
        ]);

        $to = [];
        $cc = [];
        foreach ($setting->activeRecipients as $rec) {
            if ($email = $rec->effectiveEmail()) {
                $to[$email] = $rec->effectiveName() ?? $email;
            }
        }
        if ($setting->manager_email) {
            $cc[$setting->manager_email] = $setting->manager_name ?? $setting->manager_email;
        }

        try {
            $mailable = new PrinterAlertMail($event, $printer);
            $msg = Mail::to(array_keys($to));
            if (! empty($cc)) {
                $msg->cc(array_keys($cc));
            }
            $msg->send($mailable);
            return back()->with('success', 'Test email dispatched to ' . count($to) . ' recipient(s)' . (count($cc) ? ', plus manager CC' : '') . '.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Test email failed: ' . $e->getMessage());
        }
    }
}
