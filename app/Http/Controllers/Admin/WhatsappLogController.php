<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\WhatsappLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsappLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('view-whatsapp-logs');

        $query = WhatsappLog::orderByDesc('sent_at');

        if ($request->filled('type')) {
            $query->where('notification_type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('to_number', 'like', "%{$s}%")
                ->orWhere('to_name', 'like', "%{$s}%")
                ->orWhere('body', 'like', "%{$s}%"));
        }

        $logs = $query->paginate(50)->withQueryString();

        $types = WhatsappLog::select('notification_type')
            ->whereNotNull('notification_type')
            ->distinct()
            ->orderBy('notification_type')
            ->pluck('notification_type');

        return view('admin.notifications.whatsapp-log', compact('logs', 'types'));
    }

    public function clearAll()
    {
        $this->authorize('view-whatsapp-logs');

        $count = WhatsappLog::count();
        WhatsappLog::truncate();

        ActivityLog::create([
            'model_type' => WhatsappLog::class,
            'model_id' => 0,
            'action' => 'whatsapp_logs_cleared',
            'changes' => ['rows_cleared' => $count],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "{$count} WhatsApp log entries cleared.");
    }
}
