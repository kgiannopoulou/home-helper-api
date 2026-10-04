<?php

namespace App\Mail;

use App\Models\Household;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Sunday email: budget pace, chores that slipped and the reader's own nutrition gaps.
 */
class WeeklySummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $shared  budget and chores, the same for every member
     * @param  array<string, mixed>  $nutrition  this member's own NutritionGapsQuery result
     */
    public function __construct(
        public Household $household,
        public User $user,
        public CarbonImmutable $today,
        public array $shared,
        public array $nutrition,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->household->name}: your week to {$this->today->format('j M')}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-summary', with: [
            'money' => fn (?float $amount) => $amount === null ? '–' : Money::whole($amount, $this->household->currency),
        ]);
    }
}
