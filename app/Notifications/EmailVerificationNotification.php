<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class EmailVerificationNotification extends Notification
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://127.0.0.1:5500');

        $verifyUrl = $frontendUrl . '/verify-email.html?email=' . urlencode($notifiable->getEmailForVerification());

        return (new MailMessage)
            ->subject('Verify Your ChattoShop Email Address')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Please verify your email address by clicking the button below.')
            ->action('Verify Email Address', $verifyUrl)
            ->line('If you did not create an account, no further action is required.');
    }
}
