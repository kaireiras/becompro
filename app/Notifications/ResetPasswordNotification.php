<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    protected $token;
    protected $email;

    /**
     * Create a new notification instance.
     */
    public function __construct($token, $email)
    {
        $this->token = $token;
        $this->email = $email;
        
        Log::info('📨 ResetPasswordNotification created', [
            'email' => $email,
            'token' => substr($token, 0, 10) . '...'
        ]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        Log::info('📬 Notification via channels', [
            'channels' => ['mail']
        ]);
        
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        // ✅ URL ke Next.js frontend
        $resetUrl = env('FRONTEND_URL', 'http://localhost:3000') 
                   . '/auth/resetPassword?token=' . $this->token 
                   . '&email=' . urlencode($this->email);

        Log::info('📧 Building reset password email', [
            'to' => $notifiable->email,
            'reset_url' => $resetUrl
        ]);

        return (new MailMessage)
            ->subject('Reset Password - PAD Clinic')
            ->greeting('Halo, ' . $notifiable->username . '!')
            ->line('Anda menerima email ini karena kami menerima permintaan reset password untuk akun Anda.')
            ->action('Reset Password', $resetUrl)
            ->line('Link reset password ini akan kadaluarsa dalam **60 menit**.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini.')
            ->salutation('Salam, Tim PAD Clinic');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [];
    }
}
