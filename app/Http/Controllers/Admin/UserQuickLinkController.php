<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserQuickLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserQuickLinkController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:80',
            'url'   => 'required|string|max:500',
            'icon'  => 'nullable|string|max:50',
        ]);

        $url = trim($data['url']);
        if (! preg_match('#^(https?://|/)#i', $url)) {
            $url = '/' . ltrim($url, '/');
        }

        $userId = Auth::id();
        $next = (int) (UserQuickLink::where('user_id', $userId)->max('sort_order') ?? 0) + 1;

        UserQuickLink::create([
            'user_id'    => $userId,
            'label'      => $data['label'],
            'url'        => $url,
            'icon'       => $data['icon'] ?: 'bi-link-45deg',
            'sort_order' => $next,
        ]);

        return back()->with('success', 'Quick link added.');
    }

    public function destroy(UserQuickLink $quickLink): RedirectResponse
    {
        abort_unless($quickLink->user_id === Auth::id(), 403);
        $quickLink->delete();

        return back()->with('success', 'Quick link removed.');
    }
}
