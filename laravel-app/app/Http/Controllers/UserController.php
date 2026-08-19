<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderByDesc('created_at')->get();

        return view('users.index', compact('users'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(6)],
            'role' => ['required', Rule::in(['admin', 'user'])],
        ], [
            'email.unique' => 'Email ini sudah dipakai pengguna lain.',
            'password.min' => 'Password minimal 6 karakter.',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'is_active' => true,
        ]);

        return redirect()->route('users.index')->with('status', 'Pengguna baru berhasil ditambahkan.');
    }

    public function toggle(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->withErrors(['user' => 'Anda tidak bisa menonaktifkan akun Anda sendiri.']);
        }

        if ($user->isAdmin() && $user->is_active) {
            $activeAdmins = User::where('role', 'admin')->where('is_active', true)->count();
            if ($activeAdmins <= 1) {
                return redirect()->route('users.index')->withErrors(['user' => 'Tidak bisa menonaktifkan satu-satunya Administrator aktif.']);
            }
        }

        $user->update(['is_active' => ! $user->is_active]);

        return redirect()->route('users.index')->with('status', 'Status pengguna diperbarui.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', Password::min(6)],
        ], [
            'password.min' => 'Password baru minimal 6 karakter.',
        ]);

        $user->update(['password' => $validated['password']]);

        return redirect()->route('users.index')->with('status', "Password untuk {$user->email} berhasil direset.");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->withErrors(['user' => 'Anda tidak bisa menghapus akun Anda sendiri.']);
        }

        if ($user->isAdmin()) {
            $adminCount = User::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return redirect()->route('users.index')->withErrors(['user' => 'Tidak bisa menghapus satu-satunya Administrator.']);
            }
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', 'Pengguna dihapus.');
    }
}
