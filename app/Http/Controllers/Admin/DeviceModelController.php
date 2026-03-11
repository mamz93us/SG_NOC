<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceModel;
use Illuminate\Http\Request;

class DeviceModelController extends Controller
{
    public function index(Request $request)
    {
        $query = DeviceModel::withCount('devices')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('device_type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name',         'like', "%{$s}%")
                  ->orWhere('manufacturer','like', "%{$s}%");
            });
        }

        $models = $query->paginate(30)->withQueryString();
        $types  = ['ucm','switch','router','firewall','ap','printer','server',
                   'laptop','desktop','monitor','keyboard','mouse','headset','tablet','other'];

        return view('admin.devices.models.index', compact('models', 'types'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'manufacturer'     => 'nullable|string|max:255',
            'device_type'      => 'nullable|string|max:50',
            'latest_firmware'  => 'nullable|string|max:100',
            'release_notes'    => 'nullable|string',
        ]);

        // Check for duplicate
        $exists = DeviceModel::where('name', $data['name'])
            ->where('manufacturer', $data['manufacturer'] ?? null)
            ->first();

        if ($exists) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['id' => $exists->id, 'name' => $exists->displayName()]);
            }
            return back()->with('info', "Model \"{$exists->displayName()}\" already exists.");
        }

        $model = DeviceModel::create($data);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['id' => $model->id, 'name' => $model->displayName()], 201);
        }

        return back()->with('success', "Model \"{$model->displayName()}\" created.");
    }

    public function update(Request $request, DeviceModel $deviceModel)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'manufacturer'    => 'nullable|string|max:255',
            'device_type'     => 'nullable|string|max:50',
            'latest_firmware' => 'nullable|string|max:100',
            'release_notes'   => 'nullable|string',
        ]);

        $deviceModel->update($data);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['id' => $deviceModel->id, 'name' => $deviceModel->displayName()]);
        }

        return back()->with('success', "Model \"{$deviceModel->displayName()}\" updated.");
    }

    public function destroy(DeviceModel $deviceModel)
    {
        if ($deviceModel->devices()->exists()) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['error' => 'Cannot delete: model is in use by devices.'], 422);
            }
            return back()->with('error', "Cannot delete \"{$deviceModel->displayName()}\" — it is linked to devices.");
        }

        $name = $deviceModel->displayName();
        $deviceModel->delete();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', "Model \"{$name}\" deleted.");
    }
}
