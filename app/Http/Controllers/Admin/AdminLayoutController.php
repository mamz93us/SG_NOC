<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminLayoutController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|in:classic,v2',
        ]);

        $user = Auth::user();
        $user->admin_layout_version = $data['version'];
        $user->save();

        return response()->json([
            'admin_layout_version' => $user->admin_layout_version,
        ]);
    }
}
