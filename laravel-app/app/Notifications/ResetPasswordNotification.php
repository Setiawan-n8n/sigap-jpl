<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // route('password.reset', ..., false) menghasilkan path relatif, lalu
        // url() menambahkan APP_URL di depannya -- pastikan APP_URL benar di
        // produksi (https://sigap-jpl.batimulia.com) agar tautan valid.
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $broker = Config::get('auth.defaults.passwords', 'users');
        $expire = Config::get("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject('Reset Password — SIGAP-JPL')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Kami menerima permintaan untuk mereset password akun SIGAP-JPL Anda.')
            ->line('Klik tombol di bawah ini untuk membuat password baru.')
            ->action('Reset Password', $url)
            ->line('Tautan ini akan kedaluwarsa dalam '.$expire.' menit.')
            ->line('Jika Anda tidak merasa meminta reset password, abaikan saja email ini — password Anda tidak akan berubah.')
            ->salutation('Salam, Tim SIGAP-JPL');
    }
}
