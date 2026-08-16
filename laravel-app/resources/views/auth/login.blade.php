<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — SIGAP-JPL</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-semibold text-white">SIGAP-JPL</h1>
            <p class="text-sm text-slate-400">Sistem Informasi &amp; Grafis Analisis Perlintasan — Proyek SRRL</p>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Masuk</h2>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('login.attempt') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full text-sm border rounded-lg p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" name="password" required
                           class="w-full text-sm border rounded-lg p-2">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember">
                    Ingat saya
                </label>
                <button type="submit" class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium">
                    Masuk
                </button>
            </form>
        </div>
    </div>
</body>
</html>
