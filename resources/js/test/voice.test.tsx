import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { VoiceControls } from '@/components/assistant/voice-controls';
import type { Voice } from '@/hooks/use-voice';
import type { VoiceEvent, VoiceState } from '@/lib/voice/machine';
import { initialVoiceState, micIsOn, voiceReducer } from '@/lib/voice/machine';
import { toPcm16 } from '@/lib/voice/pcm';
import { rms, VoiceActivity } from '@/lib/voice/vad';
import VoiceSettings from '@/pages/settings/voice';
import type { VoiceConfig } from '@/types/voice';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const run = (s: VoiceState, ...events: VoiceEvent['type'][]) =>
    events.reduce((acc, type) => voiceReducer(acc, { type } as VoiceEvent), s);

describe('the voice conversation machine', () => {
    test('press, talk, pause: it thinks, speaks, then listens for a follow-up', () => {
        let s = run(initialVoiceState(), 'PRESS');
        expect(s.mode).toBe('listening');

        s = run(s, 'SPEECH_END');
        expect(s.mode).toBe('thinking');
        s = voiceReducer(s, { type: 'SENT', turnId: 't1' });
        expect(s.turnId).toBe('t1');

        s = voiceReducer(s, { type: 'REPLY', speak: true });
        expect(s.mode).toBe('speaking');
        s = run(s, 'SPOKEN');
        expect(s).toMatchObject({ mode: 'listening', followUp: true });

        // Nothing said in the follow-up window: back to rest
        expect(run(s, 'NOTHING_SAID').mode).toBe('off');
    });

    test('without continuous mode an answer ends the conversation; with the wake word it rests waiting', () => {
        expect(run(initialVoiceState(false, false), 'PRESS', 'STOP').mode).toBe('thinking');
        const answered = voiceReducer(run(initialVoiceState(true, false), 'WAKE', 'STOP'), { type: 'REPLY', speak: true });
        expect(run(answered, 'SPOKEN').mode).toBe('waiting');
    });

    test('the wake word only starts listening while waiting', () => {
        expect(run(initialVoiceState(true), 'WAKE').mode).toBe('listening');
        expect(run(initialVoiceState(false), 'WAKE').mode).toBe('off');
    });

    test('talking over the answer interrupts it, and you are heard', () => {
        const speaking = voiceReducer(run(initialVoiceState(), 'PRESS', 'STOP'), { type: 'REPLY', speak: true });
        expect(run(speaking, 'INTERRUPT')).toMatchObject({ mode: 'listening', followUp: false });
        // Pressing the mic does the same
        expect(run(speaking, 'PRESS').mode).toBe('listening');
    });

    test('cancel goes back to rest from anywhere, and a late answer is dropped', () => {
        const thinking = voiceReducer(run(initialVoiceState(true), 'WAKE', 'STOP'), { type: 'SENT', turnId: 't1' });
        const cancelled = run(thinking, 'CANCEL');
        expect(cancelled).toMatchObject({ mode: 'waiting', turnId: null });
        expect(voiceReducer(cancelled, { type: 'REPLY', speak: true }).mode).toBe('waiting');
    });

    test('mute closes the microphone and nothing opens it; unmute returns to rest', () => {
        const muted = voiceReducer(run(initialVoiceState(true), 'WAKE'), { type: 'MUTE', on: true });
        expect(muted).toMatchObject({ mode: 'off', muted: true });
        expect(micIsOn(muted)).toBe(false);
        expect(run(muted, 'PRESS', 'WAKE').mode).toBe('off');
        expect(voiceReducer(muted, { type: 'MUTE', on: false }).mode).toBe('waiting');
    });

    test('turning the wake word on and off while resting', () => {
        const s = voiceReducer(initialVoiceState(), { type: 'SET_WAKE', on: true });
        expect(s.mode).toBe('waiting');
        expect(voiceReducer(s, { type: 'SET_WAKE', on: false }).mode).toBe('off');
    });

    test('an error goes back to rest and says why', () => {
        expect(voiceReducer(run(initialVoiceState(), 'PRESS'), { type: 'ERROR', message: 'No mic' })).toMatchObject({ mode: 'off', error: 'No mic' });
    });
});

describe('voice activity detection', () => {
    test('speech starts after a sustained loud stretch and ends after a pause', () => {
        const vad = new VoiceActivity({ ratio: 3, minLevel: 0.02, startMs: 100, silenceMs: 500 });
        const events: string[] = [];
        let t = 0;
        const feed = (level: number, ms: number) => {
            for (const end = t + ms; t < end; t += 50) {
                const e = vad.feed(level, t);
                if (e) {
                    events.push(`${e}@${t}`);
                }
            }
        };

        feed(0.005, 1000); // a quiet room
        feed(0.2, 30); // a click: too short
        feed(0.005, 200);
        feed(0.2, 800); // speech
        feed(0.005, 1000); // a pause

        expect(events).toEqual(['speech-start@1350', 'speech-end@2550']);
        expect(vad.isSpeaking).toBe(false);
    });

    test('rms', () => {
        expect(rms(new Float32Array([0.5, -0.5, 0.5, -0.5]))).toBeCloseTo(0.5);
        expect(rms(new Float32Array())).toBe(0);
    });
});

describe('PCM for the wake-word socket', () => {
    test('48 kHz floats become 16 kHz 16-bit samples', () => {
        const pcm = toPcm16(new Float32Array([1, 1, 1, -1, -1, -1, 0, 0, 0]), 48000);
        expect(Array.from(pcm)).toEqual([32767, -32768, 0]);
        expect(() => toPcm16(new Float32Array(4), 8000)).toThrow();
    });
});

const config: VoiceConfig = {
    driver: 'service',
    settings: { voice: 'en_GB-alan-medium', style: 'butler', rate: 1, language: null, wake_word: true, continuous: true, keep_days: null, remember: true },
    voices: [
        { id: 'en_GB-alan-medium', label: 'Alan (British English)', language: 'en_GB' },
        { id: 'el_GR-rapunzelina-low', label: 'Rapunzelina (Greek)', language: 'el_GR' },
    ],
    styles: [
        { id: 'brief', label: 'Brief: the answer in a sentence or two' },
        { id: 'butler', label: 'Butler: calm, courteous, a little formal' },
    ],
    keep_days_options: [1, 7, 30],
    wake: { available: true, phrase: 'Hey Jarvis', url: 'ws://localhost:8090/v1/wake?ticket=x' },
    max_seconds: 60,
};

function stubVoice(state: Partial<VoiceState> = {}): Voice {
    return {
        state: { ...initialVoiceState(true), ...state },
        level: 0.1,
        heard: '',
        supported: true,
        press: vi.fn(),
        cancel: vi.fn(),
        setWake: vi.fn(),
        setContinuous: vi.fn(),
        mute: vi.fn(),
    };
}

describe('the voice bar', () => {
    test('resting with the wake word: it says what to say, and the red dot shows the mic is on', () => {
        render(<VoiceControls voice={stubVoice()} config={config} />);

        expect(screen.getByRole('status').textContent).toContain('Say “Hey Jarvis”');
        expect(screen.getByLabelText('Microphone on')).toBeTruthy();
    });

    test('Space presses the mic, Escape stops; not while typing', () => {
        const voice = stubVoice({ mode: 'speaking' });
        render(
            <>
                <input aria-label="typing" />
                <VoiceControls voice={voice} config={config} />
            </>,
        );

        fireEvent.keyDown(window, { code: 'Space', key: ' ' });
        fireEvent.keyDown(window, { key: 'Escape' });
        fireEvent.keyDown(screen.getByLabelText('typing'), { code: 'Space', key: ' ' });

        expect(voice.press).toHaveBeenCalledTimes(1);
        expect(voice.cancel).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('status').textContent).toContain('talk to interrupt');
    });

    test('Stop while thinking, mute, and the switches', () => {
        const voice = stubVoice({ mode: 'thinking' });
        render(<VoiceControls voice={voice} config={config} />);

        fireEvent.click(screen.getByRole('button', { name: 'Stop' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mute the microphone' }));
        fireEvent.click(screen.getByLabelText(/Keep listening/));

        expect(voice.cancel).toHaveBeenCalled();
        expect(voice.mute).toHaveBeenCalledWith(true);
        expect(voice.setContinuous).toHaveBeenCalledWith(false);
        expect((screen.getByRole('button', { name: 'Talk' }) as HTMLButtonElement).disabled).toBe(true);
    });

    test('a browser that cannot record says so', () => {
        render(<VoiceControls voice={{ ...stubVoice(), supported: false }} config={config} />);
        expect(screen.getByText(/can't record audio/)).toBeTruthy();
    });
});

describe('Settings → Voice', () => {
    afterEach(() => vi.unstubAllGlobals());

    test('a change is saved at once, with the CSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ...config, settings: { ...config.settings, style: 'brief' } }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);
        render(<VoiceSettings voice={config} />);

        fireEvent.click(screen.getByLabelText('Brief: the answer in a sentence or two'));
        await screen.findByText('Saved.');

        expect(fetchMock).toHaveBeenCalledWith('/settings/voice', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ style: 'brief' }),
            headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'abc=' }),
        }));
    });
});
