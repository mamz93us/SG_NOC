<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\HrApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HrApiKeyController extends Controller
{
    public function index()
    {
        $keys = HrApiKey::with('creator')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.hr-api-keys.index', compact('keys'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        [$rawKey, $model] = HrApiKey::generate(
            $data['name'],
            $data['description'] ?? null,
            Auth::id()
        );

        ActivityLog::create([
            'model_type' => HrApiKey::class,
            'model_id'   => $model->id,
            'action'     => 'hr_api_key_created',
            'changes'    => ['name' => $model->name, 'description' => $model->description],
            'user_id'    => Auth::id(),
        ]);

        session()->flash('new_api_key', $rawKey);
        session()->flash('new_api_key_name', $model->name);

        return redirect('/admin/hr-api-keys')
            ->with('success', "API key \"{$model->name}\" created.");
    }

    public function revoke(HrApiKey $hrApiKey)
    {
        $hrApiKey->revoke();

        ActivityLog::create([
            'model_type' => HrApiKey::class,
            'model_id'   => $hrApiKey->id,
            'action'     => 'hr_api_key_revoked',
            'changes'    => ['name' => $hrApiKey->name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Key \"{$hrApiKey->name}\" has been revoked.");
    }

    public function destroy(HrApiKey $hrApiKey)
    {
        $name = $hrApiKey->name;
        $id   = $hrApiKey->id;
        $hrApiKey->delete();

        ActivityLog::create([
            'model_type' => HrApiKey::class,
            'model_id'   => $id,
            'action'     => 'hr_api_key_deleted',
            'changes'    => ['name' => $name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Key \"{$name}\" permanently deleted.");
    }
}
