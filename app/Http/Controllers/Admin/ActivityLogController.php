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
        if ($request->has('model_type') && $request->model_type !== '') {
            $query->where('model_type', $request->model_type);
        }

        // Filter by action
        if ($request->has('action') && $request->action !== '') {
            $query->where('action', $request->action);
        }

        // Filter by user
        if ($request->has('user_id') && $request->user_id !== '') {
            $query->where('user_id', $request->user_id);
        }

        $logs = $query->paginate(20);

        return view('admin.activity-logs', compact('logs'));
    }
}
