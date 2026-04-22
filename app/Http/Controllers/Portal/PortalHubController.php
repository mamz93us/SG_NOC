<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BrowserSession;
use App\Models\Employee;
use App\Models\ProfileEditRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PortalHubController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $activeBrowser = BrowserSession::where('user_id', $user->id)->active()->first();
        $employee      = Employee::where('email', $user->email)->first();
        $pendingEdit   = $employee
            ? ProfileEditRequest::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->exists()
            : false;

        return view('portal.hub', compact('activeBrowser', 'employee', 'pendingEdit'));
    }
}
