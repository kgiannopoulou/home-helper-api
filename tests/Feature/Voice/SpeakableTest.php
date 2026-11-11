<?php

use App\Voice\ResponseStyle;
use App\Voice\Speakable;
use App\Voice\Transcription;

/*
 * Day 48: an answer written for the screen, rewritten for the ear.
 */

test('a list becomes one sentence', function () {
    expect(Speakable::make("To buy:\n- Milk\n- Bread\n- Coffee (2)"))
        ->toBe('To buy: Milk, Bread and Coffee (2).');
});

test('money, dates and units are said as words', function () {
    expect(Speakable::make('Spent €1,234.50 by 2026-11-14; rent €620 is due.'))
        ->toBe('Spent 1234 euros 50 by Saturday 14 November; rent 620 euros is due.')
        ->and(Speakable::make('9–17 °C, 8 mm of rain, gusts of 40 km/h. Water it with 350 ml.'))
        ->toBe('9 to 17 degrees, 8 millimetres of rain, gusts of 40 kilometres an hour. Water it with 350 millilitres.');
});

test('markdown, links and ids are dropped', function () {
    expect(Speakable::make('**Done.** See [the list](https://example.com/list) [01JABCDEFGHJKMNPQRSTVWXYZ0].'))
        ->toBe('Done. See the list.');
});

test('long answers are cut at a sentence end before the style\'s limit', function () {
    $text = str_repeat('This is one sentence of a long answer. ', 30);

    $brief = Speakable::make($text, ResponseStyle::Brief->maxSpokenChars());

    expect(mb_strlen($brief))->toBeLessThanOrEqual(300)
        ->and($brief)->toEndWith('answer.')
        ->and(Speakable::make(str_repeat('word ', 200), 50))->toEndWith('…');
});

test('silence and Whisper\'s noise phrases count as nothing heard', function () {
    expect((new Transcription(''))->isEmpty())->toBeTrue()
        ->and((new Transcription(' Thank you. '))->isEmpty())->toBeTrue()
        ->and((new Transcription('Thank you, add milk'))->isEmpty())->toBeFalse();
});

test('each style tells the model it is speaking, and how', function () {
    expect(ResponseStyle::Butler->instructions('Maria'))->toContain('spoken aloud')->toContain('address Maria by name')
        ->and(ResponseStyle::Brief->instructions('Maria'))->toContain('one or two short sentences')
        ->and(ResponseStyle::values())->toBe(['brief', 'conversational', 'detailed', 'butler']);
});
