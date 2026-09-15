<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AI ▸ AI Access: who may use each AI feature that reads restricted data.
 *
 * Every change goes through AiAccess, which writes the same per-user grants
 * and denies as Users ▸ Permissions and logs ai_access_granted / _revoked as
 * security events.
 */
class AiAccessController extends Controller
{
    public function __construct(private AiAccess $access) {}

    public function index(): View
    {
        return view('admin.ai-access.index', [
            'features' => collect(AiAccess::features())
                ->map(fn (array $feature, string $key) => $feature + ['key' => $key, 'holders' => $this->access->holders($key)])
                ->values(),
            'userOptions' => User::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'feature' => 'required|string|in:'.implode(',', array_keys(AiAccess::features())),
            'user' => 'required|string|max:255',
        ]);

        $feature = AiAccess::features()[$data['feature']];

        // The picker's value starts with the user id: "12 · Name · email".
        $user = preg_match('/^\s*#?(\d+)/', $data['user'], $m) ? User::find((int) $m[1]) : null;

        if (! $user) {
            return back()->withInput()->with('error', 'Pick the person from the list: start typing their name or email.');
        }

        if ($user->hasPermission($feature['permission'])) {
            return back()->with('info', "{$user->name} can already use {$feature['name']}.");
        }

        $this->access->grant($user, $data['feature'], $request->user());

        return back()->with('success', "{$user->name} can now use {$feature['name']}.");
    }

    public function destroy(Request $request, string $feature, User $user): RedirectResponse
    {
        $definition = AiAccess::features()[$feature] ?? abort(404);

        if ($user->isSuperAdmin()) {
            return back()->with('error', "{$user->name} is a Super Admin, who holds every permission. Change their role under Users to take {$definition['name']} away.");
        }

        if (! $user->hasPermission($definition['permission'])) {
            return back()->with('info', "{$user->name} already cannot use {$definition['name']}.");
        }

        $this->access->revoke($user, $feature, $request->user());

        return back()->with('success', "{$user->name} can no longer use {$definition['name']}.");
    }
}
