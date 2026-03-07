<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->get();
        return view('admin.users.index', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', Password::min(8)],
            'role'     => 'required|in:super_admin,admin,viewer',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'],
        ]);

        ActivityLog::create([
            'model_type' => 'User',
            'model_id'   => $user->id,
            'action'     => 'created',
            'changes'    => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$data['name']} created successfully.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'role'     => 'required|in:super_admin,admin,viewer',
            'password' => ['nullable', Password::min(8)],
        ]);

        $old = $user->only(['name', 'email', 'role']);

        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->role  = $data['role'];

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        ActivityLog::create([
            'model_type' => 'User',
            'model_id'   => $user->id,
            'action'     => 'updated',
            'changes'    => [
                'old' => $old,
                'new' => $user->only(['name', 'email', 'role']),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$user->name} updated successfully.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        $snapshot = $user->only(['id', 'name', 'email', 'role']);
        $user->delete();

        ActivityLog::create([
            'model_type' => 'User',
            'model_id'   => $snapshot['id'],
            'action'     => 'deleted',
            'changes'    => $snapshot,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$snapshot['name']} deleted.");
    }
}
