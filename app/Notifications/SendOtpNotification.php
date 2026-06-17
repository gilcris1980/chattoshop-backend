<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class SendOtpNotification extends Notification
{
    use Queueable;

    public string $otp;
    public string $type;

    public function __construct(string $otp, string $type = 'email_verification')
    {
        $this->otp = $otp;
        $this->type = $type;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        if ($this->type === 'password_reset') {
            return (new MailMessage)
                ->subject('Your ChattoShop Password Reset Code')
                ->greeting('Hello!')
                ->line('You are receiving this email because we received a password reset request for your account.')
                ->line('Your password reset code is: <strong>' . $this->otp . '</strong>')
                ->line('This code will expire in 15 minutes.')
                ->line('If you did not request a password reset, no further action is required.');
        }

        return (new MailMessage)
            ->subject('Verify Your ChattoShop Email Address')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Thank you for registering with ChattoShop!')
            ->line('Your email verification code is: <strong>' . $this->otp . '</strong>')
            ->line('This code will expire in 10 minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
