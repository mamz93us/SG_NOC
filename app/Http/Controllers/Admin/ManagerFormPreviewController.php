<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessApp;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\IdentityGroup;
use App\Models\InternetAccessLevel;
use App\Models\NetworkFloor;
use App\Models\OffboardingToken;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowRequest;
use Illuminate\View\View;

/**
 * Admin → Workflows → Manager Form Previews.
 *
 * The onboarding and offboarding manager forms are only reachable through a
 * one-shot emailed token, which means nobody can look at them without burning a
 * real request. These routes render the exact same Blade views against
 * fabricated, unsaved models so the forms can be reviewed at any time.
 *
 * Nothing here touches the database: the token and workflow objects are built
 * in memory and never saved, and the views are told they are in preview mode so
 * the submit buttons are inert.
 */
class ManagerFormPreviewController extends Controller
{
    /**
     * GET /admin/form-previews/onboarding
     */
    public function onboarding(): View
    {
        $workflow = new WorkflowRequest([
            'type' => 'onboarding',
            'status' => 'awaiting_manager',
            'payload' => [
                'display_name' => 'Sara Al-Rashid',
                'first_name' => 'Sara',
                'last_name' => 'Al-Rashid',
                'upn' => 'sara.alrashid@samirgroup.com',
                'job_title' => 'Accountant',
                'department' => 'Finance',
                'branch' => 'Jeddah',
                'start_date' => now()->addDays(7)->toDateString(),
                'hr_reference' => 'PREVIEW-0000',
                'manager_name' => 'Preview Manager',
            ],
        ]);
        $workflow->id = 0;

        $tokenRecord = new OnboardingManagerToken([
            'token' => 'preview',
            'manager_email' => 'manager@samirgroup.com',
            'manager_name' => 'Preview Manager',
            'expires_at' => now()->addDays(14),
        ]);
        $tokenRecord->id = 0;
        $tokenRecord->setRelation('workflow', $workflow);

        // Not branch-scoped here — the preview has no real branch, and showing
        // every floor is more useful than showing none.
        $floors = NetworkFloor::orderBy('sort_order')->orderBy('name')->get();

        $groups = IdentityGroup::where('security_enabled', false)
            ->where('mail_enabled', true)
            ->whereNull('group_type')
            ->orderBy('display_name')
            ->get();

        $internetLevels = InternetAccessLevel::ordered()->get();

        $businessApps = BusinessApp::selectable()
            ->filter(fn ($a) => $a->isConfigured())
            ->values();

        return view('public.onboarding_form', [
            'tokenRecord' => $tokenRecord,
            'workflow' => $workflow,
            'payload' => $workflow->payload,
            'floors' => $floors,
            'groups' => $groups,
            'internetLevels' => $internetLevels,
            'businessApps' => $businessApps,
            'preview' => true,
        ]);
    }

    /**
     * GET /admin/form-previews/offboarding
     */
    public function offboarding(): View
    {
        $token = new OffboardingToken([
            'token' => 'preview',
            'manager_email' => 'manager@samirgroup.com',
            'manager_name' => 'Preview Manager',
            'expires_at' => now()->addDays(7),
            'payload' => [
                'display_name' => 'Sara Al-Rashid',
                'upn' => 'sara.alrashid@samirgroup.com',
                'job_title' => 'Accountant',
                'department' => 'Finance',
                'last_day' => now()->addDays(14)->toDateString(),
                'reason' => 'Resignation',
                'hr_reference' => 'PREVIEW-0000',
                'live_graph_data' => [
                    'mailbox' => ['size_bytes' => 4_509_715_660],
                    'onedrive' => ['size_bytes' => 12_562_450_186],
                    'groups' => ['Finance – All', 'Jeddah – Staff', 'Azure_Internet_Med'],
                ],
            ],
        ]);
        $token->id = 0;
        $token->workflow_id = 0;

        $assets = collect([
            $this->fakeAsset(1, 'JED-LAP-0142', 'Laptop', 'HP EliteBook 840 G9', 'SN-PREVIEW-LAP'),
            $this->fakeAsset(2, 'JED-PHN-0311', 'IP Phone', 'Grandstream GRP2601', 'SN-PREVIEW-PHN'),
            $this->fakeAsset(3, 'JED-MON-0087', 'Monitor', 'Dell 22"', 'SN-PREVIEW-MON'),
        ]);

        // Real names here — the transfer picker is the one part of the form that
        // is worth seeing populated with actual data.
        $activeEmployees = Employee::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('public.offboarding_form', [
            'token' => $token,
            'payload' => $token->payload,
            'assets' => $assets,
            'activeEmployees' => $activeEmployees,
            'preview' => true,
        ]);
    }

    /**
     * An unsaved EmployeeAsset with its device relation already attached, so the
     * view's `$a->device?->...` chain resolves without hitting the database.
     */
    private function fakeAsset(int $id, string $code, string $type, string $model, string $serial): EmployeeAsset
    {
        $device = new Device([
            'asset_code' => $code,
            'type' => $type,
            'model' => $model,
            'serial_number' => $serial,
        ]);
        $device->id = $id;

        $asset = new EmployeeAsset;
        $asset->id = $id;
        $asset->setRelation('device', $device);

        return $asset;
    }
}
