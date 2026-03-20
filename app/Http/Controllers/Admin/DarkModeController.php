<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DarkModeController extends Controller
{
    public function toggle(): JsonResponse
    {
        $user = Auth::user();
        $user->dark_mode = ! $user->dark_mode;
        $user->save();

        return response()->json([
            'dark_mode' => $user->dark_mode,
        ]);
    }
}
