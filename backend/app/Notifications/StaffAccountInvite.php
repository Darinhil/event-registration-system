<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAccountInvite extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $frontendUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim($this->frontendUrl, '/').'/staff/setup-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Set up your staff account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A staff account has been created for you.')
            ->line('Sign in with your email address: '.$notifiable->email)
            ->action('Set your password', $url)
            ->line('This secure link expires in 60 minutes. If you were not expecting this invitation, you can ignore this email.');
    }
}
