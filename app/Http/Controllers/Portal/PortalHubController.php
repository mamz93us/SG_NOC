<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BrowserSession;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PortalHubController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $activeBrowser = BrowserSession::where('user_id', $user->id)->active()->first();
        $employee      = Employee::where('email', $user->email)->first();

        $pendingEdit = WorkflowRequest::where('requested_by', $user->id)
            ->where('type', MyProfileController::WORKFLOW_TYPE)
            ->whereIn('status', ['pending', 'executing', 'manager_input_pending'])
            ->exists();

        return view('portal.hub', compact('activeBrowser', 'employee', 'pendingEdit'));
    }
}
