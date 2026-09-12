<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeSource;
use App\Models\Branch;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\Readers\AccessTransactionReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Attendance → BioTime Sources: the SQL Server databases punches are
 * read from — BioTime attendance or ZKBio access control — each with its own
 * "Test connection" and "Sync now".
 */
class BiotimeSourceController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance.sources.index', [
            'sources' => BiotimeSource::with('defaultBranch:id,name')->withCount('employees')->orderBy('name')->get(),
            'driverAvailable' => BioTimeConnection::driverAvailable(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new BiotimeSource([
            'source_type' => BiotimeSource::TYPE_ICLOCK,
            'time_column' => 'create_time',
            'code_column' => 'BADGENUMBER',
            'lookback_days' => 0,
            'port' => 1433,
            'trust_server_certificate' => true,
            'enabled' => true,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $source = BiotimeSource::create($data);
        $this->log($source, 'created', $data);

        return $this->afterSave($request, $source, "Source \"{$source->name}\" added.");
    }

    public function edit(BiotimeSource $source): View
    {
        return $this->form($source);
    }

    public function update(Request $request, BiotimeSource $source): RedirectResponse
    {
        $data = $this->validated($request, $source);

        // Blank keeps the stored password.
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        // Punches are unique per source only. Pointing a source that already
        // holds punches at another database or table would mix their ids.
        if ($source->hasPunches() && ($data['database'] !== $source->database || $data['source_type'] !== $source->source_type)) {
            return back()->withInput()->withErrors([
                'database' => "This source already holds punches from \"{$source->database}\" ({$source->source_type}). "
                    .'Add a new source for a different database or table — reusing this one would mix their punches.',
            ]);
        }

        $rereadNeeded = $source->hasPunches() && (
            ($data['time_column'] ?? null) !== $source->time_column
            || ($data['code_column'] ?? null) !== $source->code_column
            || $data['stores_utc'] !== (bool) $source->stores_utc
            || ($data['timezone'] ?? null) !== $source->timezone
        );

        // The rule deciding WHICH employee a code belongs to changed. Codes
        // already linked are the ones now most likely wrong, and retryUnlinked()
        // would never revisit them — so re-decide every non-manual one.
        $matchingChanged = $source->hasPunches() && (
            ($data['code_prefix'] ?? null) !== $source->code_prefix
            || (int) ($data['default_branch_id'] ?? 0) !== (int) $source->default_branch_id
        );

        $source->update($data);
        $this->log($source, 'updated', $data);

        $message = "Source \"{$source->name}\" saved.";
        if ($rereadNeeded) {
            $message .= " Punches already stored keep their old times and codes — re-read them with: php artisan biotime:sync --source={$source->id} --since=YYYY-MM-DD";
        }
        if ($matchingChanged) {
            AttendanceTask::queue('relink', ['source_id' => $source->id, 'all' => true],
                "Re-match every code on {$source->name}", Auth::id());
            $message .= ' Every code on this source is being matched again — the banner above shows when it is done.';
        }

        return $this->afterSave($request, $source, $message);
    }

    public function test(BiotimeSource $source, BioTimeSyncService $sync): RedirectResponse
    {
        try {
            $result = $sync->test($source);
        } catch (\Throwable $e) {
            $message = BioTimeConnection::cleanError($e);
            $source->forceFill(['last_test_at' => now(), 'last_test_result' => mb_substr('Failed: '.$message, 0, 500)])->save();

            return redirect()->route('admin.attendance.sources.index')
                ->with('test_error', $message)
                ->with('test_error_source', $source->name);
        }

        $source->forceFill([
            'last_test_at' => now(),
            'last_test_result' => mb_substr("OK · {$result['latency_ms']} ms · {$result['watermark']}", 0, 500),
        ])->save();

        return redirect()->route('admin.attendance.sources.index')
            ->with('test_result', $result + ['source' => $source->name]);
    }

    /**
     * Queued, never inline: a sync can take minutes, and doing it in the
     * request held a PHP-FPM worker until nginx gave up with a 504.
     * `attendance:work` starts it within a minute; the banner shows progress.
     */
    public function sync(BiotimeSource $source): RedirectResponse
    {
        AttendanceTask::queue('sync', ['source_id' => $source->id], "Sync {$source->name}", Auth::id());

        return redirect()->route('admin.attendance.sources.index')
            ->with('success', "Sync of \"{$source->name}\" queued — it starts within a minute. The banner above shows when it is done.");
    }

    private function form(BiotimeSource $source): View
    {
        return view('admin.attendance.sources.form', [
            'source' => $source,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'types' => BiotimeSource::TYPES,
            'codeColumns' => BiotimeSource::CODE_COLUMNS,
            'timezones' => BiotimeSource::timezoneChoices(),
        ]);
    }

    private function afterSave(Request $request, BiotimeSource $source, string $message): RedirectResponse
    {
        if ($request->boolean('then_test')) {
            return $this->test($source, app(BioTimeSyncService::class))->with('success', $message);
        }

        return redirect()->route('admin.attendance.sources.index')->with('success', $message);
    }

    private function validated(Request $request, ?BiotimeSource $source): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'source_type' => ['required', Rule::in(array_keys(BiotimeSource::TYPES))],
            'time_column' => ['nullable', Rule::in(AccessTransactionReader::TIME_COLUMNS)],
            'code_column' => ['nullable', Rule::in(array_keys(BiotimeSource::CODE_COLUMNS))],
            'code_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'lookback_days' => 'nullable|integer|min:0|max:30',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'database' => 'required|string|max:128',
            'username' => 'required|string|max:128',
            'password' => ($source ? 'nullable' : 'required').'|string|max:255',
            'timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
            'default_branch_id' => 'nullable|integer|exists:branches,id',
            'import_from' => 'nullable|date',
        ]);

        $data['time_column'] = $data['source_type'] === BiotimeSource::TYPE_ACCESS
            ? ($data['time_column'] ?? 'create_time')
            : null;
        // Only the legacy table joins a user table, so only it picks a column.
        $data['code_column'] = $data['source_type'] === BiotimeSource::TYPE_CHECKINOUT
            ? ($data['code_column'] ?? 'BADGENUMBER')
            : null;
        $data['code_prefix'] = trim((string) ($data['code_prefix'] ?? '')) ?: null;
        $data['lookback_days'] = (int) ($data['lookback_days'] ?? 0);
        $data['trust_server_certificate'] = $request->boolean('trust_server_certificate');
        $data['stores_utc'] = $request->boolean('stores_utc');
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
