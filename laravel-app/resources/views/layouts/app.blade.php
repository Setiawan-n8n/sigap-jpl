<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIGAP-JPL')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800">
    <nav class="bg-slate-900 text-white px-6 py-4 flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-6">
            <a href="{{ route('videos.index') }}" class="font-semibold text-lg">SIGAP-JPL</a>
            <div class="flex items-center gap-4 text-sm text-slate-300">
                <a href="{{ route('videos.index') }}" class="hover:text-white">Unggah Video</a>
                <a href="{{ route('dashboard.index') }}" class="hover:text-white">Dashboard</a>
                <a href="{{ route('locations.index') }}" class="hover:text-white">Lokasi JPL</a>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <span class="text-xs text-slate-400 hidden md:inline">Sistem Informasi &amp; Grafis Analisis Perlintasan — Proyek SRRL</span>
            @auth
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-slate-300">{{ auth()->user()->name }}</span>
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

        @yield('content')
    </main>
</body>
</html>
