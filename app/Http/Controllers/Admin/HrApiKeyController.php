<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        session()->flash('new_api_key', $rawKey);
        session()->flash('new_api_key_name', $model->name);

        return redirect('/admin/hr-api-keys')
            ->with('success', "API key \"{$model->name}\" created.");
    }

    public function revoke(HrApiKey $hrApiKey)
    {
        $hrApiKey->revoke();
        return back()->with('success', "Key \"{$hrApiKey->name}\" has been revoked.");
    }

    public function destroy(HrApiKey $hrApiKey)
    {
        $name = $hrApiKey->name;
        $hrApiKey->delete();
        return back()->with('success', "Key \"{$name}\" permanently deleted.");
    }
}
