<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\AlertState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AlertRuleController extends Controller
{
    public function index()
    {
        $rules = AlertRule::withCount([
            'states as active_count' => fn ($q) => $q->where('state', 'alerted'),
        ])->orderBy('name')->get();

        return view('admin.alerts.index', compact('rules'));
    }

    public function create()
    {
        $rule = new AlertRule();
        return view('admin.alerts.form', compact('rule'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:200',
            'description'      => 'nullable|string',
            'severity'         => 'required|in:warning,critical',
            'target_type'      => 'required|in:sensor,printer,host',
            'sensor_class'     => 'nullable|string|max:100',
            'operator'         => 'required|in:<=,>=,<,>,==,!=',
            'threshold_value'  => 'required|numeric',
            'delay_seconds'    => 'nullable|integer|min:0|max:86400',
            'interval_seconds' => 'nullable|integer|min:60|max:86400',
            'recovery_alert'   => 'boolean',
            'disabled'         => 'boolean',
            'notify_email'     => 'boolean',
            'notify_emails'    => 'nullable|string',
            'notify_slack'     => 'boolean',
            'slack_webhook'    => 'nullable|url',
        ]);

        // Checkboxes: default to false when not submitted
        $validated['recovery_alert'] = $request->boolean('recovery_alert');
        $validated['disabled']       = $request->boolean('disabled');
        $validated['notify_email']   = $request->boolean('notify_email');
        $validated['notify_slack']   = $request->boolean('notify_slack');

        AlertRule::create($validated);

        return redirect()->route('admin.alert-rules.index')
            ->with('success', 'Alert rule created successfully.');
    }

    public function edit(AlertRule $alertRule)
    {
        $rule = $alertRule;
        return view('admin.alerts.form', compact('rule'));
    }

    public function update(Request $request, AlertRule $alertRule)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:200',
            'description'      => 'nullable|string',
            'severity'         => 'required|in:warning,critical',
            'target_type'      => 'required|in:sensor,printer,host',
            'sensor_class'     => 'nullable|string|max:100',
            'operator'         => 'required|in:<=,>=,<,>,==,!=',
            'threshold_value'  => 'required|numeric',
            'delay_seconds'    => 'nullable|integer|min:0|max:86400',
            'interval_seconds' => 'nullable|integer|min:60|max:86400',
            'recovery_alert'   => 'boolean',
            'disabled'         => 'boolean',
            'notify_email'     => 'boolean',
            'notify_emails'    => 'nullable|string',
            'notify_slack'     => 'boolean',
            'slack_webhook'    => 'nullable|url',
        ]);

        $validated['recovery_alert'] = $request->boolean('recovery_alert');
        $validated['disabled']       = $request->boolean('disabled');
        $validated['notify_email']   = $request->boolean('notify_email');
        $validated['notify_slack']   = $request->boolean('notify_slack');

        $alertRule->update($validated);

        return redirect()->route('admin.alert-rules.index')
            ->with('success', 'Alert rule updated successfully.');
    }

    public function destroy(AlertRule $alertRule)
    {
        $alertRule->delete();

        return redirect()->route('admin.alert-rules.index')
            ->with('success', 'Alert rule deleted.');
    }

    public function states(AlertRule $alertRule)
    {
        $states = $alertRule->states()
            ->orderByRaw("FIELD(state, 'alerted', 'acknowledged', 'ok')")
            ->orderByDesc('last_alerted_at')
            ->paginate(25);

        return view('admin.alerts.states', compact('alertRule', 'states'));
    }

    public function acknowledge(AlertState $alertState)
    {
        $alertState->update([
            'state'           => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => Auth::user()->name ?? Auth::user()->email,
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    public function alerts()
    {
        $activeStates = AlertState::with('rule')
            ->whereIn('state', ['alerted', 'acknowledged'])
            ->orderByRaw("FIELD(state, 'alerted', 'acknowledged')")
            ->orderBy('last_alerted_at', 'desc')
            ->get();

        $totalActive      = $activeStates->count();
        $totalCritical    = $activeStates->filter(fn ($s) => $s->rule?->severity === 'critical')->count();
        $totalWarning     = $activeStates->filter(fn ($s) => $s->rule?->severity === 'warning')->count();
        $totalAcknowledged = $activeStates->where('state', 'acknowledged')->count();

        return view('admin.alerts.dashboard', compact(
            'activeStates',
            'totalActive',
            'totalCritical',
            'totalWarning',
            'totalAcknowledged'
        ));
    }
}
