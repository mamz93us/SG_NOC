<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\NocEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $query = Incident::with(['branch', 'assignedTo', 'createdBy'])
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'investigating' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 END")
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title',       'like', "%{$s}%")
                  ->orWhere('description','like', "%{$s}%");
            });
        }

        $incidents  = $query->paginate(50)->withQueryString();
        $branches   = Branch::orderBy('name')->get(['id', 'name']);
        $users      = User::orderBy('name')->get(['id', 'name']);
        $openCount  = Incident::open()->count();

        return view('admin.noc.incidents.index', compact('incidents', 'branches', 'users', 'openCount'));
    }

    public function create(Request $request)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $users    = User::orderBy('name')->get(['id', 'name']);
        $event    = null;

        if ($request->filled('event_id')) {
            $event = NocEvent::find($request->event_id);
        }

        return view('admin.noc.incidents.form', compact('branches', 'users', 'event'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'severity'         => 'required|in:low,medium,high,critical',
            'branch_id'        => 'nullable|exists:branches,id',
            'assigned_to'      => 'nullable|exists:users,id',
            'noc_event_id'     => 'nullable|exists:noc_events,id',
        ]);

        $data['created_by'] = Auth::id();
        $data['status']     = 'open';

        $incident = Incident::create($data);

        ActivityLog::log('Created incident #' . $incident->id . ': ' . $incident->title);

        return redirect()->route('admin.noc.incidents.show', $incident)
            ->with('success', 'Incident #' . $incident->id . ' created.');
    }

    public function show(Incident $incident)
    {
        $incident->load(['branch', 'assignedTo', 'createdBy', 'nocEvent', 'comments.user']);
        $users = User::orderBy('name')->get(['id', 'name']);
        return view('admin.noc.incidents.show', compact('incident', 'users'));
    }

    public function edit(Incident $incident)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $users    = User::orderBy('name')->get(['id', 'name']);
        return view('admin.noc.incidents.form', compact('incident', 'branches', 'users'));
    }

    public function update(Request $request, Incident $incident)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'severity'         => 'required|in:low,medium,high,critical',
            'status'           => 'required|in:open,investigating,resolved,closed',
            'branch_id'        => 'nullable|exists:branches,id',
            'assigned_to'      => 'nullable|exists:users,id',
            'resolution_notes' => 'nullable|string',
        ]);

        if ($data['status'] === 'resolved' && $incident->status !== 'resolved') {
            $data['resolved_at'] = now();
        }

        $incident->update($data);

        ActivityLog::log('Updated incident #' . $incident->id . ': status=' . $data['status']);

        return redirect()->route('admin.noc.incidents.show', $incident)
            ->with('success', 'Incident updated.');
    }

    public function addComment(Request $request, Incident $incident)
    {
        $request->validate(['body' => 'required|string']);

        IncidentComment::create([
            'incident_id' => $incident->id,
            'user_id'     => Auth::id(),
            'body'        => $request->body,
        ]);

        return redirect()->route('admin.noc.incidents.show', $incident)
            ->with('success', 'Comment added.');
    }

    public function createFromEvent($eventId)
    {
        $event = NocEvent::findOrFail($eventId);

        return redirect()->route('admin.noc.incidents.create', [
            'event_id' => $event->id,
        ]);
    }
}
