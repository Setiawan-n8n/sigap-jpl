<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIGAP-JPL')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800">
    @php $isAdmin = auth()->check() && auth()->user()->isAdmin(); @endphp
    <nav class="bg-slate-900 text-white px-6 py-4 flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-6">
            <a href="{{ $isAdmin ? route('videos.index') : route('dashboard.online') }}" class="font-semibold text-lg">SIGAP-JPL</a>
            <div class="flex items-center gap-4 text-sm text-slate-300">
                @if ($isAdmin)
                    <a href="{{ route('videos.index') }}" class="hover:text-white {{ request()->routeIs('videos.index') ? 'text-white' : '' }}">Unggah Video</a>
                    <a href="{{ route('locations.index') }}" class="hover:text-white {{ request()->routeIs('locations.*') ? 'text-white' : '' }}">Lokasi JPL</a>
                    <a href="{{ route('users.index') }}" class="hover:text-white {{ request()->routeIs('users.*') ? 'text-white' : '' }}">Kelola Pengguna</a>
                @else
                    <div class="relative group">
                        <button type="button" class="hover:text-white {{ request()->routeIs('dashboard.*') ? 'text-white' : '' }} flex items-center gap-1">
                            Dashboard
                            <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </button>
                        <div class="absolute left-0 top-full pt-2 hidden group-hover:block z-10">
                            <div class="bg-white text-slate-800 rounded-lg shadow-lg py-1 w-52 text-sm">
                                <a href="{{ route('dashboard.online') }}" class="block px-4 py-2 hover:bg-slate-100">Dashboard Online</a>
                                <a href="{{ route('dashboard.offline') }}" class="block px-4 py-2 hover:bg-slate-100">Dashboard Offline</a>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-4">
            <span class="text-xs text-slate-400 hidden md:inline">Sistem Informasi &amp; Grafis Analisis Perlintasan — Proyek SRRL</span>
            @auth
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-slate-300">{{ auth()->user()->name }} · {{ $isAdmin ? 'Administrator' : 'User' }}</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-slate-300 hover:text-white underline">Keluar</button>
                    </form>
                </div>
            @endauth
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg bg-green-100 text-green-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
