import { Mic, MicOff, Square, Volume2 } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import type { Voice } from '@/hooks/use-voice';
import { micIsOn } from '@/lib/voice/machine';
import { cn } from '@/lib/utils';
import type { VoiceConfig } from '@/types/voice';

const STATUS: Record<Voice['state']['mode'], string> = {
    off: 'Press the mic (or Space) and ask',
    waiting: 'Listening for the wake word',
    listening: 'Listening…',
    thinking: 'Thinking…',
    speaking: 'Speaking: talk to interrupt',
};

/**
 * The voice bar on the assistant page (docs/voice.md#in-the-browser): the mic
 * button, what it is doing, a level meter, stop, and the switches. A red dot
 * shows whenever the microphone is open.
 */
export function VoiceControls({
    voice,
    config,
}: {
    voice: Voice;
    config: VoiceConfig;
}) {
    const { state, level } = voice;
    const open = micIsOn(state) && !state.muted;

    // Space: press the mic; Escape: stop. Not while typing.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target;
            if (target instanceof Element && target.closest('input, textarea, select, [contenteditable=true]')) {
                return;
            }
            if (e.code === 'Space' && !e.repeat) {
                e.preventDefault();
                voice.press();
            } else if (e.key === 'Escape') {
                voice.cancel();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [voice]);

    if (!voice.supported) {
        return (
            <p className="text-xs text-muted-foreground">
                This browser can't record audio, so voice is off. Type instead.
            </p>
        );
    }

    const status =
        state.mode === 'waiting'
            ? `Say “${config.wake.phrase}”`
            : state.mode === 'listening' && state.followUp
              ? 'Anything else? Listening…'
              : STATUS[state.mode];

    return (
        <div className="flex flex-col gap-2 rounded-lg border p-2" aria-label="Voice">
            <div className="flex items-center gap-3">
                <Button
                    type="button"
                    size="icon"
                    variant={state.mode === 'listening' ? 'destructive' : 'outline'}
                    className={cn('size-11 rounded-full', state.mode === 'listening' && 'animate-pulse')}
                    aria-label={state.mode === 'listening' ? 'Done talking' : 'Talk'}
                    aria-pressed={state.mode === 'listening'}
                    disabled={state.muted || state.mode === 'thinking'}
                    onClick={voice.press}
                >
                    {state.mode === 'speaking' ? <Volume2 /> : <Mic />}
                </Button>

                <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 text-sm font-medium" role="status" aria-live="polite">
                        {open && (
                            <span className="inline-block size-2 shrink-0 rounded-full bg-red-500" title="The microphone is on" aria-label="Microphone on" />
                        )}
                        {state.muted ? 'Muted' : status}
                    </p>
                    {state.error ? (
                        <p className="truncate text-xs text-destructive">{state.error}</p>
                    ) : voice.heard && state.mode !== 'off' && state.mode !== 'waiting' ? (
                        <p className="truncate text-xs text-muted-foreground">“{voice.heard}”</p>
                    ) : null}
                    <div className="mt-1 h-1 w-full overflow-hidden rounded bg-muted" aria-hidden>
                        <div className="h-full bg-primary transition-[width] duration-75" style={{ width: `${Math.min(100, level * 400)}%` }} />
                    </div>
                </div>

                {(state.mode === 'thinking' || state.mode === 'speaking' || state.mode === 'listening') && (
                    <Button type="button" size="sm" variant="ghost" onClick={voice.cancel} aria-label="Stop">
                        <Square /> Stop
                    </Button>
                )}
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={state.muted ? 'Unmute the microphone' : 'Mute the microphone'}
                    aria-pressed={state.muted}
                    onClick={() => voice.mute(!state.muted)}
                >
                    {state.muted ? <MicOff /> : <Mic className="opacity-50" />}
                </Button>
            </div>

            <div className="flex flex-wrap gap-4 px-1 text-xs text-muted-foreground">
                {config.wake.available && (
                    <label className="flex items-center gap-1.5">
                        <input type="checkbox" checked={state.wake} disabled={!config.wake.url} onChange={(e) => voice.setWake(e.target.checked)} />
                        Wake word “{config.wake.phrase}”{!config.wake.url && ' (turn it on in Settings → Voice)'}
                    </label>
                )}
                <label className="flex items-center gap-1.5">
                    <input type="checkbox" checked={state.continuous} onChange={(e) => voice.setContinuous(e.target.checked)} />
                    Keep listening after an answer
                </label>
                {config.driver === 'browser' && <span>Speech by your browser (it may send audio to its maker).</span>}
            </div>
        </div>
    );
}
