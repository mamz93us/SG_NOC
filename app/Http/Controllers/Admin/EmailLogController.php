<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('view-email-logs');

        $query = EmailLog::orderByDesc('sent_at');

        if ($request->filled('type')) {
            $query->where('notification_type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('to_email', 'like', "%{$s}%")
                ->orWhere('subject', 'like', "%{$s}%"));
        }

        $logs = $query->paginate(50)->withQueryString();

        $types = EmailLog::select('notification_type')
            ->distinct()
            ->orderBy('notification_type')
            ->pluck('notification_type');

        return view('admin.notifications.email-log', compact('logs', 'types'));
    }

    public function clearAll(Request $request)
    {
        $this->authorize('view-email-logs');

        $count = EmailLog::count();
        EmailLog::truncate();

        ActivityLog::create([
            'model_type' => EmailLog::class,
            'model_id'   => 0,
            'action'     => 'email_logs_cleared',
            'changes'    => ['rows_cleared' => $count],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "{$count} email log entries cleared.");
    }
}
