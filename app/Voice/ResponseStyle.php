<?php

namespace App\Voice;

use App\Enums\Concerns\HasValues;

/**
 * How the assistant answers out loud (docs/voice.md#voices-and-styles). The
 * style is told to the model with the household context, and caps how much
 * of the answer is spoken: a written answer can be long, a spoken one can't.
 */
enum ResponseStyle: string
{
    use HasValues;

    case Brief = 'brief';
    case Conversational = 'conversational';
    case Detailed = 'detailed';
    case Butler = 'butler';

    public function label(): string
    {
        return match ($this) {
            self::Brief => 'Brief: the answer in a sentence or two',
            self::Conversational => 'Conversational: a few natural sentences',
            self::Detailed => 'Detailed: everything that matters',
            self::Butler => 'Butler: calm, courteous, a little formal',
        };
    }

    /** Added to the context of a spoken turn */
    public function instructions(string $name): string
    {
        $spoken = 'This answer will be spoken aloud, not read: no lists, headings, symbols or ids; say amounts and dates the way a person would ("twelve euros forty", "on Friday").';

        return $spoken.' '.match ($this) {
            self::Brief => 'Answer in one or two short sentences, the most important thing first. Offer more only if asked.',
            self::Conversational => 'Answer in a few natural sentences, about 60 words at most; turn several items into one sentence ("milk, bread and coffee").',
            self::Detailed => 'You may use up to about 120 words; for several items say "first…, then…, and finally…".',
            self::Butler => "Speak as a calm, courteous English butler: address {$name} by name now and then, understated and precise (\"Very good.\", \"If I may suggest…\"). Two or three sentences.",
        };
    }

    /** At most this many characters are spoken; the rest is on the screen */
    public function maxSpokenChars(): int
    {
        return match ($this) {
            self::Brief => 300,
            self::Conversational => 600,
            self::Detailed => 1200,
            self::Butler => 450,
        };
    }
}
