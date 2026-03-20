<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ItTask;
use App\Models\ItTaskComment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ItTaskController extends Controller
{
    public function index(Request $request)
    {
        $query = ItTask::with(['assignedTo', 'branch', 'createdBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tasks = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $statusCounts = [
            'todo'        => ItTask::where('status', 'todo')->count(),
            'in_progress' => ItTask::where('status', 'in_progress')->count(),
            'blocked'     => ItTask::where('status', 'blocked')->count(),
            'done'        => ItTask::where('status', 'done')->count(),
        ];

        return view('admin.tasks.index', [
            'tasks'        => $tasks,
            'branches'     => Branch::orderBy('name')->get(),
            'users'        => User::orderBy('name')->get(),
            'statusCounts' => $statusCounts,
            'filters'      => $request->only(['status', 'priority', 'assigned_to', 'branch_id', 'search', 'overdue']),
        ]);
    }

    public function myTasks()
    {
        $tasks = ItTask::with(['branch', 'createdBy'])
            ->assignedTo(Auth::id())
            ->orderByRaw("FIELD(status, 'in_progress', 'todo', 'blocked', 'on_hold', 'done')")
            ->get()
            ->groupBy('status');

        return view('admin.tasks.my-tasks', [
            'grouped' => $tasks,
        ]);
    }

    public function kanban()
    {
        $statuses = ['todo', 'in_progress', 'blocked', 'done'];
        $columns  = [];

        foreach ($statuses as $status) {
            $columns[$status] = ItTask::with(['assignedTo'])
                ->where('status', $status)
                ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
                ->orderBy('due_date')
                ->get();
        }

        return view('admin.tasks.kanban', [
            'columns'  => $columns,
            'statuses' => $statuses,
        ]);
    }

    public function create()
    {
        return view('admin.tasks.create', [
            'branches' => Branch::orderBy('name')->get(),
            'users'    => User::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string',
            'type'            => 'required|in:maintenance,project,support,change,other',
            'priority'        => 'required|in:low,medium,high,urgent',
            'assigned_to'     => 'nullable|exists:users,id',
            'branch_id'       => 'nullable|exists:branches,id',
            'due_date'        => 'nullable|date',
            'estimated_hours' => 'nullable|numeric|min:0|max:9999',
        ]);

        $data['created_by'] = Auth::id();
        $task = ItTask::create($data);

        ActivityLog::create([
            'model_type' => 'ItTask',
            'model_id'   => $task->id,
            'action'     => 'created',
            'changes'    => $data,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', 'Task created successfully.');
    }

    public function show(ItTask $task)
    {
        $task->load(['comments.user', 'assignedTo', 'branch', 'createdBy']);

        return view('admin.tasks.show', compact('task'));
    }

    public function edit(ItTask $task)
    {
        return view('admin.tasks.edit', [
            'task'     => $task,
            'branches' => Branch::orderBy('name')->get(),
            'users'    => User::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ItTask $task)
    {
        $data = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string',
            'type'            => 'required|in:maintenance,project,support,change,other',
            'priority'        => 'required|in:low,medium,high,urgent',
            'status'          => 'required|in:todo,in_progress,blocked,on_hold,done',
            'assigned_to'     => 'nullable|exists:users,id',
            'branch_id'       => 'nullable|exists:branches,id',
            'due_date'        => 'nullable|date',
            'estimated_hours' => 'nullable|numeric|min:0|max:9999',
        ]);

        $old = $task->only(array_keys($data));
        $task->update($data);

        ActivityLog::create([
            'model_type' => 'ItTask',
            'model_id'   => $task->id,
            'action'     => 'updated',
            'changes'    => ['old' => $old, 'new' => $data],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', 'Task updated successfully.');
    }

    public function destroy(ItTask $task)
    {
        $user = Auth::user();

        if ($task->created_by !== $user->id && !$user->isAdmin()) {
            abort(403, 'You can only delete tasks you created.');
        }

        ActivityLog::create([
            'model_type' => 'ItTask',
            'model_id'   => $task->id,
            'action'     => 'deleted',
            'changes'    => ['title' => $task->title],
            'user_id'    => Auth::id(),
        ]);

        $task->delete();

        return redirect()->route('admin.tasks.index')
            ->with('success', 'Task deleted.');
    }

    public function addComment(Request $request, ItTask $task)
    {
        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        ItTaskComment::create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'body'    => $request->body,
        ]);

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', 'Comment added.');
    }

    public function logTime(Request $request, ItTask $task)
    {
        $request->validate([
            'hours' => 'required|numeric|min:0.5|max:24',
        ]);

        $task->increment('logged_hours', $request->hours);

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', $request->hours . ' hour(s) logged.');
    }

    public function updateStatus(Request $request, ItTask $task)
    {
        $request->validate([
            'status' => 'required|in:todo,in_progress,blocked,on_hold,done',
        ]);

        $old = $task->status;
        $task->update(['status' => $request->status]);

        ActivityLog::create([
            'model_type' => 'ItTask',
            'model_id'   => $task->id,
            'action'     => 'status_changed',
            'changes'    => ['old' => $old, 'new' => $request->status],
            'user_id'    => Auth::id(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status'  => $task->status,
            ]);
        }

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', 'Status updated to ' . str_replace('_', ' ', $task->status) . '.');
    }
}
