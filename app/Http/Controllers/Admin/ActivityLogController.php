<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * The audit log.
     *
     * Now that every model write lands here (see AuditObserver), the filters
     * matter more than they did when 22 models were covered: a month of rows is
     * only readable if you can narrow it to one person, one action, or one
     * record. The matching indexes went on in the
     * add_audit_columns_and_indexes_to_activity_logs migration — before that
     * every one of these filters was a full table scan.
     */
    public function index(Request $request)
    {
        $query = ActivityLog::with('user')->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            // 'system' means the unattended writes — the scheduler, the queue and
            // the webhooks, which have no user_id. Those used to be either
            // unlogged or booked to whoever signed up first, so being able to ask
            // for them specifically is the point of having them at all.
            if ($request->user_id === 'system') {
                $query->whereNull('user_id');
            } else {
                $query->where('user_id', $request->user_id);
            }
        }

        // Sign-ins, denials and anything that moved a permission. This is the
        // view a privilege-escalation review actually wants, and it is a handful
        // of rows among hundreds of thousands of ordinary edits.
        if ($request->boolean('security')) {
            $query->security();
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('model_type', 'like', "%{$s}%")
                    ->orWhere('model_label', 'like', "%{$s}%")
                    ->orWhere('actor_label', 'like', "%{$s}%")
                    ->orWhere('ip_address', 'like', "%{$s}%")
                    ->orWhere('model_id', 'like', "%{$s}%")
                    ->orWhere('changes', 'like', "%{$s}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // The dropdowns. `distinct` over the whole table is cheap enough with the
        // new indexes and there are only ~230 model types and ~40 actions, but it
        // is cached for a minute so a busy page doesn't repeat it per request.
        [$modelTypes, $actions] = cache()->remember('activity-log.filters', 60, fn () => [
            ActivityLog::query()->distinct()->orderBy('model_type')->pluck('model_type')->all(),
            ActivityLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
        ]);

        $users = User::orderBy('name')->get(['id', 'name', 'role']);

        $logs = $query->paginate(30)->withQueryString();

        return view('admin.activity-logs', compact('logs', 'modelTypes', 'actions', 'users'));
    }
}
