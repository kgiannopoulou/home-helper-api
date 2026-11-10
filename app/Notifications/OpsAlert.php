<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to whoever runs the server (App\Ops\Alerts).
 */
class OpsAlert extends Notification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $title,
        public array $context = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] '.$this->title)
            ->line($this->title);

        foreach ($this->context as $name => $value) {
            $mail->line("**{$name}:** ".(is_scalar($value) ? (string) $value : json_encode($value)));
        }

        return $mail
            ->action('System health', url('/system/health'))
            ->line('Details are in storage/logs/ops-*.log, and in the main log under the same request_id.');
    }
}
