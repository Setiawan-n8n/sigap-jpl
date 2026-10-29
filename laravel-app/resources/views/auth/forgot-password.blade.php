<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password — SIGAP-JPL</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-semibold text-white">SIGAP-JPL</h1>
            <p class="text-sm text-slate-400">Sistem Informasi &amp; Grafis Analisis Perlintasan — Proyek SRRL</p>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h2 class="text-lg font-semibold mb-1">Lupa Password</h2>
            <p class="text-sm text-slate-500 mb-4">Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat password baru.</p>

            @if (session('status'))
                <div class="mb-4 rounded-lg bg-green-100 text-green-800 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('password.email') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full text-sm border rounded-lg p-2">
                </div>
                <button type="submit" class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium">
                    Kirim Tautan Reset
                </button>
            </form>

            <div class="mt-4 text-center">
                <a href="{{ route('login') }}" class="text-sm text-slate-600 hover:text-slate-900 underline">Kembali ke halaman Masuk</a>
            </div>
        </div>
    </div>
</body>
</html>
