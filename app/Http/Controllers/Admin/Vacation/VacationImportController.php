<?php

namespace App\Http\Controllers\Admin\Vacation;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Vacation\VacationEmployee;
use App\Models\Vacation\VacationImport;
use App\Services\Vacation\VacationImporter;
use App\Services\Vacation\VacationSheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Admin → Vacations → Import: HR uploads Oracle's two vacation sheets — the
 * balance sheet and the details sheet, either or both — and the NOC takes
 * them in through VacationImporter, the same path the Oracle API will use.
 *
 * Which sheet is which is read from its header, not from the box it was put
 * in. Both are read before anything is imported, so a wrong file stops the
 * whole upload. Reading and importing both takes about a second for the
 * whole company, so it runs in the request. The files are not kept.
 */
class VacationImportController extends Controller
{
    public function index(): View
    {
        return view('admin.vacations.imports.index', [
            'books' => VacationEmployee::books(),
            'defaultBook' => VacationEmployee::defaultBook(),
            'imports' => VacationImport::with('importer:id,name')->latest('id')->limit(30)->get(),
        ]);
    }

    public function store(Request $request, VacationSheetReader $reader, VacationImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'book' => ['required', 'string', 'in:'.implode(',', array_keys(VacationEmployee::books()))],
            'as_of' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:today'],
            // By extension: fileinfo calls some Oracle .xls files "CDFV2", which no mimes rule knows.
            'balances_file' => ['nullable', 'file', 'extensions:xls,xlsx,csv', 'max:20480'],
            'absences_file' => ['nullable', 'file', 'extensions:xls,xlsx,csv', 'max:20480'],
        ], [
            'as_of.before_or_equal' => 'A balance cannot be as of a day that has not come yet.',
        ]);

        $files = array_filter([$request->file('balances_file'), $request->file('absences_file')]);

        if ($files === []) {
            return back()->withInput()->with('error', 'Choose the balance sheet, the details sheet, or both.');
        }

        $sheets = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();

            try {
                $sheet = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
            } catch (RuntimeException $e) {
                return back()->withInput()->with('error', "{$name}: {$e->getMessage()}");
            } catch (Throwable $e) {
                return back()->withInput()->with('error', "{$name} could not be opened as a spreadsheet ({$e->getMessage()}).");
            }

            if (isset($sheets[$sheet['kind']])) {
                return back()->withInput()->with('error', 'Both files are the '.($sheet['kind'] === VacationImport::KIND_BALANCES ? 'balance' : 'details')
                    .' sheet. Choose one of each, or just one.');
            }

            $sheets[$sheet['kind']] = $sheet + ['filename' => $name];
        }

        $asOf = CarbonImmutable::parse($data['as_of']);
        $imports = [];

        // Balances first: their sheet carries Oracle's person ids for the people both sheets name.
        if ($sheet = $sheets[VacationImport::KIND_BALANCES] ?? null) {
            $imports[] = $importer->importBalances($data['book'], $asOf, $sheet['rows'], VacationImport::SOURCE_SHEET, $sheet['filename'], Auth::id());
        }
        if ($sheet = $sheets[VacationImport::KIND_ABSENCES] ?? null) {
            $imports[] = $importer->importAbsences($data['book'], $sheet['rows'], VacationImport::SOURCE_SHEET, $sheet['filename'], Auth::id());
        }

        foreach ($imports as $import) {
            ActivityLog::create([
                'model_type' => VacationImport::class,
                'model_id' => $import->id,
                'model_label' => $import->filename,
                'action' => 'vacation_import',
                'changes' => [
                    'book' => $import->book,
                    'kind' => $import->kind,
                    'as_of' => $import->as_of?->toDateString(),
                    'summary' => $import->summary(),
                ],
                'user_id' => Auth::id(),
            ]);
        }

        $message = collect($imports)->map(fn (VacationImport $import) => $import->summary())->implode("\n");
        $problems = collect($imports)->sum(fn (VacationImport $import) => count($import->notes ?? []));

        return redirect()
            ->route('admin.vacations.imports.index')
            ->with('success', $message.($problems > 0 ? "\nSee the notes on the imports below." : ''));
    }
}
