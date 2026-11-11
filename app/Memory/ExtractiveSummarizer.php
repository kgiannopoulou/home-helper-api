<?php

namespace App\Memory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A summary without a model (AI_PROVIDER=offline): each question, the first
 * sentence of its answer, and every confirmed change, newest kept when it
 * grows past 30 lines. Cruder than a model's, but it keeps names, amounts and
 * dates verbatim, which is what search needs.
 */
class ExtractiveSummarizer implements Summarizer
{
    private const MAX_LINES = 30;

    public function summarize(?string $previous, array $messages): string
    {
        $lines = $previous ? array_values(array_filter(array_map('trim', explode("\n", $previous)))) : [];
        $question = null;

        foreach ($messages as $m) {
            /** @var CarbonImmutable|null $at an unsaved message has none */
            $at = $m->created_at;
            $date = $at?->timezone(config('homehelper.timezone'))->format('j M Y');
            switch ($m->role) {
                case 'user':
                    $question = '- '.($date ? "{$date}: " : '').'asked "'.Str::limit(self::oneLine($m->text()), 160).'"';
                    $lines[] = $question;
                    break;
                case 'assistant':
                    $answer = self::firstSentence($m->text());
                    $lastLine = array_key_last($lines);
                    if ($answer !== '' && $question !== null && $lastLine !== null) {
                        $lines[$lastLine] .= ' → '.Str::limit($answer, 200);
                        $question = null;
                    }
                    break;
                case 'event':
                    $lines[] = '- '.Str::limit(self::oneLine($m->text()), 200);
                    break;
            }
        }

        return implode("\n", array_slice($lines, -self::MAX_LINES));
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function firstSentence(string $text): string
    {
        $text = self::oneLine($text);

        return preg_match('/^(.+?[.!?:])(\s|$)/u', $text, $m) ? $m[1] : $text;
    }
}
