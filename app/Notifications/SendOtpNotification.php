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
        $subject = $this->type === 'password_reset'
            ? 'Your ChattoShop Password Reset Code'
            : 'Verify Your ChattoShop Email Address';

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.otp-notification', [
                'otp' => $this->otp,
                'type' => $this->type,
                'name' => $notifiable->name,
            ]);
    }
}
