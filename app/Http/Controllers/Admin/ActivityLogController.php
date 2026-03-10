<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Display activity logs
     */
    public function index(Request $request)
    {
        $query = ActivityLog::with('user')->orderBy('created_at', 'desc');

        // Filter by model type
        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Search across subject/details
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('model_type', 'like', "%{$s}%")
                  ->orWhere('model_id', 'like', "%{$s}%")
                  ->orWhere('changes', 'like', "%{$s}%");
            });
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Get unique model types for filter dropdown
        $modelTypes = ActivityLog::select('model_type')->distinct()->orderBy('model_type')->pluck('model_type');

        // Get unique actions for filter dropdown
        $actions = ActivityLog::select('action')->distinct()->orderBy('action')->pluck('action');

        $logs = $query->paginate(30)->withQueryString();

        return view('admin.activity-logs', compact('logs', 'modelTypes', 'actions'));
    }
}
