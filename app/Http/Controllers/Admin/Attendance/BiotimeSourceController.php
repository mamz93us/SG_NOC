<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\BiotimeSource;
use App\Models\Branch;
use App\Services\Attendance\BioTimeConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Attendance → BioTime Sources: the SQL Server databases punches are
 * read from, each with its own "Test connection" and "Sync now".
 */
class BiotimeSourceController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance.sources.index', [
            'sources' => BiotimeSource::with('defaultBranch:id,name')->withCount('employees')->orderBy('name')->get(),
            'driverAvailable' => BioTimeConnection::driverAvailable(),
            'columns' => BioTimeConnection::COLUMNS,
        ]);
    }

    public function create(): View
    {
        return view('admin.attendance.sources.form', [
            'source' => new BiotimeSource(['port' => 1433, 'trust_server_certificate' => true, 'enabled' => true]),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $source = BiotimeSource::create($data);
        $this->log($source, 'created', $data);

        return $this->afterSave($request, $source, "BioTime source \"{$source->name}\" added.");
    }

    public function edit(BiotimeSource $source): View
    {
        return view('admin.attendance.sources.form', [
            'source' => $source,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, BiotimeSource $source): RedirectResponse
    {
        $data = $this->validated($request, $source);

        // Blank keeps the stored password.
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        // biotime_id is only unique per source. Pointing a source that already
        // holds punches at another database would upsert its ids over them.
        if ($source->last_id > 0 && $data['database'] !== $source->database) {
            return back()->withInput()->withErrors([
                'database' => 'This source already holds punches from "'.$source->database.'". '
                    .'Add a new source for a different database — reusing this one would overwrite its punches.',
            ]);
        }

        $source->update($data);
        $this->log($source, 'updated', $data);

        return $this->afterSave($request, $source, "BioTime source \"{$source->name}\" saved.");
    }

    public function test(BiotimeSource $source, BioTimeConnection $bioTime): RedirectResponse
    {
        try {
            $result = $bioTime->test($source);
        } catch (\Throwable $e) {
            $message = BioTimeConnection::cleanError($e);
            $source->forceFill(['last_test_at' => now(), 'last_test_result' => mb_substr('Failed: '.$message, 0, 500)])->save();

            return redirect()->route('admin.attendance.sources.index')
                ->with('test_error', $message)
                ->with('test_error_source', $source->name);
        }

        $source->forceFill([
            'last_test_at' => now(),
            'last_test_result' => "OK · {$result['latency_ms']} ms · max id {$result['max_id']}",
        ])->save();

        return redirect()->route('admin.attendance.sources.index')
            ->with('test_result', $result + ['source' => $source->name]);
    }

    /**
     * Runs inline: production has no queue worker, and the operator wants to
     * see what happened. Capped at 20k rows so a first backfill cannot 504 —
     * the scheduler picks up the rest.
     */
    public function sync(BiotimeSource $source): RedirectResponse
    {
        @set_time_limit(300);

        try {
            $exit = Artisan::call('biotime:sync', ['--source' => $source->id, '--max-rows' => 20000]);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            return redirect()->route('admin.attendance.sources.index')
                ->with('error', 'Sync failed: '.BioTimeConnection::cleanError($e));
        }

        return redirect()->route('admin.attendance.sources.index')
            ->with($exit === 0 ? 'success' : 'error', $output ?: 'Sync finished.');
    }

    private function afterSave(Request $request, BiotimeSource $source, string $message): RedirectResponse
    {
        if ($request->boolean('then_test')) {
            return $this->test($source, app(BioTimeConnection::class));
        }

        return redirect()->route('admin.attendance.sources.index')->with('success', $message);
    }

    private function validated(Request $request, ?BiotimeSource $source): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'database' => 'required|string|max:128',
            'username' => 'required|string|max:128',
            'password' => ($source ? 'nullable' : 'required').'|string|max:255',
            'default_branch_id' => 'nullable|integer|exists:branches,id',
            'import_from' => 'nullable|date',
        ]);

        $data['trust_server_certificate'] = $request->boolean('trust_server_certificate');
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }

    private function log(BiotimeSource $source, string $action, array $data): void
    {
        ActivityLog::create([
            'model_type' => 'BiotimeSource',
            'model_id' => $source->id,
            'action' => $action,
            'changes' => Arr::except($data, ['password']) + ['password_changed' => ! empty($data['password'])],
            'user_id' => Auth::id(),
        ]);
    }
}
