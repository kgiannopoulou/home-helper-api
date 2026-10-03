<?php

namespace App\Notifications;

use App\Models\Invite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvite extends Notification
{
    public function __construct(public Invite $invite, public string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $household = $this->invite->household;
        $from = $this->invite->inviter?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("Join {$household->name} on Home Helper")
            ->line("{$from} invited you to share the shopping list, chores, money and planner of {$household->name}.")
            ->action('Join the household', $this->url)
            ->line('Open the link in the Home Helper app after logging in with this email address. It works until '.$this->invite->expires_at->toFormattedDayDateString().'.');
    }
}
