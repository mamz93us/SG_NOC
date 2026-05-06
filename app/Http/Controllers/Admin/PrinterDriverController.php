<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Printer;
use App\Models\PrinterDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PrinterDriverController extends Controller
{
    public function index(): View
    {
        $drivers = PrinterDriver::with(['printer.branch', 'uploadedBy'])
            ->latest()
            ->paginate(20);

        $printers = Printer::with('branch')
            ->orderBy('printer_name')
            ->get();

        return view('admin.printers.drivers.index', compact('drivers', 'printers'));
    }

    public function create(Request $request): View
    {
        $printers = Printer::with('branch')->orderBy('printer_name')->get();
        $selectedPrinterId = $request->query('printer_id');

        return view('admin.printers.drivers.create', compact('printers', 'selectedPrinterId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'printer_id'    => 'nullable|exists:printers,id',
            'manufacturer'  => 'nullable|string|max:100',
            'model_pattern' => 'nullable|string|max:200',
            'driver_name'   => 'required|string|max:255',
            'inf_path'      => 'nullable|string|max:500',
            'os_type'       => 'required|in:windows_x64,windows_x86,mac,universal',
            'version'       => 'nullable|string|max:50',
            'notes'         => 'nullable|string',
            'is_active'     => 'nullable|boolean',
            'driver_file'   => 'nullable|file|mimes:zip|max:204800',
        ]);

        $data['is_active']    = $request->boolean('is_active', true);
        $data['uploaded_by']  = auth()->id();

        if ($request->hasFile('driver_file')) {
            $file        = $request->file('driver_file');
            $zipName     = Str::slug($request->manufacturer ?? 'driver')
                . '-' . Str::slug($request->driver_name)
                . '-' . now()->format('Ymd')
                . '.zip';

            $data['driver_file_path']  = $file->storeAs('printer-drivers', $zipName, 'private');
            $data['original_filename'] = $file->getClientOriginalName();
        }

        // Remove the file input from data
        unset($data['driver_file']);

        PrinterDriver::create($data);

        return redirect('/admin/printers/drivers')->with('success', 'Driver "' . $data['driver_name'] . '" added successfully.');
    }

    public function edit(PrinterDriver $printerDriver): View
    {
        $printers = Printer::with('branch')->orderBy('printer_name')->get();

        return view('admin.printers.drivers.create', [
            'printers'          => $printers,
            'driver'            => $printerDriver,
            'selectedPrinterId' => $printerDriver->printer_id,
            'editing'           => true,
        ]);
    }

    public function update(Request $request, PrinterDriver $printerDriver): RedirectResponse
    {
        $data = $request->validate([
            'printer_id'    => 'nullable|exists:printers,id',
            'manufacturer'  => 'nullable|string|max:100',
            'model_pattern' => 'nullable|string|max:200',
            'driver_name'   => 'required|string|max:255',
            'inf_path'      => 'nullable|string|max:500',
            'os_type'       => 'required|in:windows_x64,windows_x86,mac,universal',
            'version'       => 'nullable|string|max:50',
            'notes'         => 'nullable|string',
            'is_active'     => 'nullable|boolean',
            'driver_file'   => 'nullable|file|mimes:zip|max:204800',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('driver_file')) {
            // Delete old file first
            if ($printerDriver->driver_file_path) {
                Storage::disk('private')->delete($printerDriver->driver_file_path);
            }

            $file    = $request->file('driver_file');
            $zipName = Str::slug($request->manufacturer ?? 'driver')
                . '-' . Str::slug($request->driver_name)
                . '-' . now()->format('Ymd')
                . '.zip';

            $data['driver_file_path']  = $file->storeAs('printer-drivers', $zipName, 'private');
            $data['original_filename'] = $file->getClientOriginalName();
        }

        unset($data['driver_file']);

        $printerDriver->update($data);

        return redirect('/admin/printers/drivers')->with('success', 'Driver updated.');
    }

    public function destroy(PrinterDriver $printerDriver): RedirectResponse
    {
        if ($printerDriver->driver_file_path) {
            Storage::disk('private')->delete($printerDriver->driver_file_path);
        }

        $name = $printerDriver->driver_name;
        $printerDriver->delete();

        return redirect('/admin/printers/drivers')->with('success', "Driver \"{$name}\" deleted.");
    }

    public function download(PrinterDriver $printerDriver)
    {
        abort_if(! $printerDriver->driver_file_path, 404, 'No file attached to this driver.');

        ActivityLog::create([
            'model_type' => PrinterDriver::class,
            'model_id'   => $printerDriver->id,
            'action'     => 'downloaded',
            'changes'    => [
                'driver_name' => $printerDriver->driver_name,
                'filename'    => $printerDriver->original_filename,
            ],
            'user_id' => auth()->id(),
        ]);

        return Storage::disk('private')->download(
            $printerDriver->driver_file_path,
            $printerDriver->original_filename ?? basename($printerDriver->driver_file_path)
        );
    }
}
