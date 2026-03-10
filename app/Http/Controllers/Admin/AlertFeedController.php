<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\NocEvent;
use Illuminate\Http\Request;

class AlertFeedController extends Controller
{
    public function index(Request $request)
    {
        $query = NocEvent::with(['acknowledgedBy', 'resolvedBy'])
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'acknowledged' THEN 2 WHEN 'resolved' THEN 3 END")
            ->orderByDesc('last_seen');

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title',   'like', "%{$s}%")
                  ->orWhere('message','like', "%{$s}%");
            });
        }
        if ($request->filled('date_from')) {
            $query->where('first_seen', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('first_seen', '<=', $request->date_to . ' 23:59:59');
        }

        $events   = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        // Stats
        $openCount    = NocEvent::where('status', 'open')->count();
        $ackedCount   = NocEvent::where('status', 'acknowledged')->count();
        $criticalOpen = NocEvent::where('status', 'open')->where('severity', 'critical')->count();

        return view('admin.noc.alert-feed', compact(
            'events', 'branches', 'openCount', 'ackedCount', 'criticalOpen'
        ));
    }

    public function timeline($id)
    {
        $event = NocEvent::with(['acknowledgedBy', 'resolvedBy'])->findOrFail($id);

        $timeline = [
            ['time' => $event->first_seen->format('M d, H:i:s'), 'label' => 'Alert Created', 'icon' => 'bi-exclamation-circle-fill', 'color' => 'danger'],
        ];

        if ($event->status === 'acknowledged' || $event->status === 'resolved') {
            $timeline[] = [
                'time'  => $event->updated_at->format('M d, H:i:s'),
                'label' => 'Acknowledged' . ($event->acknowledgedBy ? ' by ' . $event->acknowledgedBy->name : ''),
                'icon'  => 'bi-eye-fill',
                'color' => 'warning',
            ];
        }

        if ($event->status === 'resolved') {
            $timeline[] = [
                'time'  => $event->resolved_at ? $event->resolved_at->format('M d, H:i:s') : $event->updated_at->format('M d, H:i:s'),
                'label' => 'Resolved' . ($event->resolvedBy ? ' by ' . $event->resolvedBy->name : ''),
                'icon'  => 'bi-check-circle-fill',
                'color' => 'success',
            ];
        }

        return response()->json([
            'event'    => $event,
            'timeline' => $timeline,
        ]);
    }
}
