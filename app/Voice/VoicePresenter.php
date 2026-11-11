<?php

namespace App\Voice;

use App\Ai\Presenter;
use App\Models\User;
use App\Models\VoiceSettings;

/**
 * Voice turns and the voice configuration, as the API and the page receive them.
 */
class VoicePresenter
{
    public function __construct(private Presenter $present) {}

    /**
     * @param  array<string, mixed>  $turn  VoiceTurns::handle()
     * @return array<string, mixed>
     */
    public function turn(array $turn): array
    {
        return [
            'turn_id' => $turn['turn_id'],
            'heard' => $turn['heard'],
            'language' => $turn['language'],
            'reply' => $turn['reply'],
            'spoken' => $turn['spoken'],
            'audio_url' => $turn['audio_url'],
            'cancelled' => $turn['cancelled'],
            'conversation' => $turn['conversation'] ? $this->present->conversation($turn['conversation']) : null,
            'messages' => $this->present->messages($turn['messages']),
            'actions' => $this->present->actions($turn['actions']),
        ];
    }

    /**
     * What the page needs to talk: who does the speech, the person's settings,
     * the choices, and a ticket for the wake-word socket.
     *
     * @return array<string, mixed>
     */
    public function config(User $user): array
    {
        $settings = VoiceSettings::for($user);
        $driver = (string) config('homehelper.voice.driver');
        $wake = $driver === 'service' && $settings->wake_word ? WakeTicket::issue($user) : null;

        return [
            'driver' => $driver,
            'settings' => $settings->toPublic(),
            'voices' => array_map(fn (string $v) => self::voice($v), (array) config('homehelper.voice.voices')),
            'styles' => array_map(fn (ResponseStyle $s) => ['id' => $s->value, 'label' => $s->label()], ResponseStyle::cases()),
            'keep_days_options' => config('homehelper.voice.keep_days_options'),
            'wake' => [
                'available' => $driver === 'service',
                'phrase' => config('homehelper.voice.wake_phrase'),
                'url' => $wake ? config('homehelper.voice.wake_url').'?ticket='.$wake : null,
            ],
            'max_seconds' => 60,
        ];
    }

    /** @return array{id: string, label: string, language: string} en_GB-alan-medium → "Alan (British English)" */
    public static function voice(string $id): array
    {
        [$language, $speaker] = array_pad(explode('-', $id), 2, $id);
        $languages = ['en_GB' => 'British English', 'en_US' => 'American English', 'el_GR' => 'Greek'];

        return [
            'id' => $id,
            'label' => ucwords(str_replace('_', ' ', $speaker)).' ('.($languages[$language] ?? $language).')',
            'language' => $language,
        ];
    }
}
