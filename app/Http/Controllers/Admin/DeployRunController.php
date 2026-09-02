<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeployRun;
use App\Models\DeployServer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Run history — the record of who deployed what, when, and whether it worked. */
class DeployRunController extends Controller
{
    public function index(Request $request): View
    {
        $runs = DeployRun::with(['server', 'user'])
            ->when($request->filled('server'), fn ($q) => $q->where('deploy_server_id', $request->integer('server')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.deploy.runs.index', [
            'runs' => $runs,
            'servers' => DeployServer::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(DeployRun $run): View
    {
        $run->load(['server', 'command', 'user']);

        return view('admin.deploy.runs.show', compact('run'));
    }

    /** Polled by the console page so it can show the saved status once the proxy reports. */
    public function status(DeployRun $run)
    {
        return response()->json([
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'duration' => $run->durationLabel(),
        ]);
    }
}
