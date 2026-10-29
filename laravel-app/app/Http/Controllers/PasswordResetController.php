<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Tampilkan form "Lupa Password" (minta alamat email).
     */
    public function showLinkRequestForm()
    {
        return view('auth.forgot-password');
    }

    /**
     * Kirim email berisi tautan reset password.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return back()
                ->withErrors(['email' => 'Terlalu banyak permintaan. Mohon tunggu beberapa saat sebelum mencoba lagi.'])
                ->onlyInput('email');
        }

        // Untuk status lain (terkirim ATAU email tidak ditemukan) tampilkan
        // pesan yang sama, demi keamanan: mencegah orang menebak-nebak email
        // mana yang terdaftar di sistem.
        return back()->with(
            'status',
            'Jika email tersebut terdaftar, tautan reset password telah dikirim. Silakan cek kotak masuk (dan folder spam) Anda.'
        );
    }

    /**
     * Tampilkan form membuat password baru (dibuka dari tautan di email).
     */
    public function showResetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Simpan password baru.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ], [
            'email.required' => 'Email wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password baru minimal 6 karakter.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with(
                'status',
                'Password berhasil diubah. Silakan masuk dengan password baru Anda.'
            );
        }

        $messages = [
            Password::INVALID_TOKEN => 'Tautan reset password tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.',
            Password::INVALID_USER => 'Email tersebut tidak terdaftar.',
            Password::RESET_THROTTLED => 'Terlalu banyak percobaan. Mohon tunggu beberapa saat.',
        ];

        return back()
            ->withErrors(['email' => $messages[$status] ?? 'Gagal mereset password. Silakan coba lagi.'])
            ->onlyInput('email');
    }
}
