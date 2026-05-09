<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class PaletteSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q    = trim((string) $request->query('q', ''));
        $user = Auth::user();
        $out  = ['contacts' => [], 'branches' => []];

        if (mb_strlen($q) < 2) {
            return response()->json($out);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        if ($user?->can('view-contacts') && Route::has('admin.contacts.edit')) {
            $out['contacts'] = Contact::where(function ($w) use ($like) {
                    $w->where('first_name', 'like', $like)
                      ->orWhere('last_name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone', 'like', $like);
                })
                ->limit(5)
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'email' => $c->email,
                    'url'   => route('admin.contacts.edit', $c),
                ])->all();
        }

        if ($user?->can('view-branches') && Route::has('admin.branches.edit')) {
            $out['branches'] = Branch::where('name', 'like', $like)
                ->limit(5)
                ->get(['id', 'name'])
                ->map(fn ($b) => [
                    'id'   => $b->id,
                    'name' => $b->name,
                    'url'  => route('admin.branches.edit', $b),
                ])->all();
        }

        return response()->json($out);
    }
}
