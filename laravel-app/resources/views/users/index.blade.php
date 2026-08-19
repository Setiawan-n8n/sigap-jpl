@extends('layouts.app')

@section('title', 'SIGAP-JPL — Kelola Pengguna')

@section('content')
<div class="grid gap-8 md:grid-cols-2">
    <div class="bg-white rounded-xl shadow p-6 md:order-2">
        <h1 class="text-xl font-semibold mb-1">Tambah Pengguna Baru</h1>
        <p class="text-sm text-slate-500 mb-4">Pengguna dengan role "User" hanya dapat mengakses menu Dashboard (Online &amp; Offline).</p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('users.store') }}" method="POST" class="space-y-3">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1">Nama Lengkap</label>
                <input type="text" name="name" value="{{ old('name') }}" class="w-full text-sm border rounded-lg p-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full text-sm border rounded-lg p-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Password</label>
                <input type="password" name="password" minlength="6" class="w-full text-sm border rounded-lg p-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Role</label>
                <select name="role" class="w-full text-sm border rounded-lg p-2">
                    <option value="user">User (akses Dashboard saja)</option>
                    <option value="admin">Administrator (akses penuh)</option>
                </select>
            </div>
            <button type="submit" class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium">Simpan Pengguna</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow p-6 md:order-1">
        <h2 class="text-lg font-semibold mb-1">Daftar Pengguna</h2>
        <p class="text-sm text-slate-500 mb-4">Total {{ $users->count() }} akun terdaftar.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left border-b text-xs text-slate-500 uppercase">
                        <th class="py-2">Nama</th>
                        <th class="py-2">Email</th>
                        <th class="py-2">Role</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b align-top">
                            <td class="py-2">{{ $user->name }}</td>
                            <td class="py-2">{{ $user->email }}</td>
                            <td class="py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $user->isAdmin() ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $user->isAdmin() ? 'Administrator' : 'User' }}
                                </span>
                            </td>
                            <td class="py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="py-2">
                                <details class="inline-block">
                                    <summary class="text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1 font-medium cursor-pointer inline-block">Kelola</summary>
                                    <div class="mt-2 flex flex-col gap-2 min-w-[220px]">
                                        <form action="{{ route('users.toggle', $user) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="w-full text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1.5 font-medium">
                                                {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </button>
                                        </form>
                                        <form action="{{ route('users.reset-password', $user) }}" method="POST" class="flex gap-1">
                                            @csrf
                                            <input type="password" name="password" placeholder="Password baru" minlength="6" required
                                                   class="text-xs border rounded-lg p-1.5 flex-1">
                                            <button type="submit" class="text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-2 py-1 font-medium">Reset</button>
                                        </form>
                                        <form action="{{ route('users.destroy', $user) }}" method="POST"
                                              onsubmit="return confirm('Hapus pengguna {{ $user->email }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-xs bg-red-100 hover:bg-red-200 text-red-800 rounded-lg px-2.5 py-1.5 font-medium">Hapus</button>
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
