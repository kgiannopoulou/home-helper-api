<?php

namespace App\Providers;

use App\Voice\BrowserSpeech;
use App\Voice\FakeVoice;
use App\Voice\SpeechToText;
use App\Voice\TextToSpeech;
use App\Voice\VoiceServiceClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Which speech engines answer (homehelper.voice.driver, docs/voice.md).
 */
class VoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeVoice::class);

        $engine = fn () => match (config('homehelper.voice.driver')) {
            'service' => new VoiceServiceClient(
                url: rtrim((string) config('homehelper.voice.url'), '/'),
                token: config('homehelper.voice.token'),
                timeout: (int) config('homehelper.voice.timeout'),
            ),
            'browser' => new BrowserSpeech,
            'fake' => $this->app->make(FakeVoice::class),
            default => throw new InvalidArgumentException('VOICE_DRIVER must be service, browser or fake.'),
        };

        $this->app->bind(SpeechToText::class, $engine);
        $this->app->bind(TextToSpeech::class, $engine);
    }
}
