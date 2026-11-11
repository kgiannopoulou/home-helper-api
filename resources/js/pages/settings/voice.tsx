import { Head } from '@inertiajs/react';
import { Play, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { forgetVoiceHistory, saveSettings, speak } from '@/lib/voice/api';
import { edit as editVoice } from '@/routes/voice';
import type { VoiceConfig } from '@/types/voice';

const field =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs';

type Settings = VoiceConfig['settings'];

/**
 * Settings → Voice (docs/voice.md#settings): how Home Helper sounds, how it
 * answers out loud, the wake word, and what happens to voice conversations.
 * Each change is saved at once.
 */
export default function VoiceSettings({ voice }: { voice: VoiceConfig }) {
    const [settings, setSettings] = useState<Settings>(voice.settings);
    const [note, setNote] = useState<string | null>(null);

    const save = async (change: Partial<Settings>) => {
        setSettings((s) => ({ ...s, ...change }));
        try {
            const fresh = await saveSettings(change);
            setSettings(fresh.settings);
            setNote('Saved.');
        } catch (e) {
            setNote((e as Error).message);
        }
    };

    const tryVoice = async () => {
        const sample =
            settings.style === 'butler'
                ? 'Good evening. The shopping list has four items, and the basil would like some water.'
                : 'You need milk, bread and coffee. The basil needs water today.';
        try {
            const { audio_url } = await speak(sample, settings.voice, settings.rate);
            if (audio_url) {
                void new Audio(audio_url).play();
            } else if ('speechSynthesis' in window) {
                const u = new SpeechSynthesisUtterance(sample);
                u.rate = settings.rate;
                window.speechSynthesis.speak(u);
            }
        } catch (e) {
            setNote((e as Error).message);
        }
    };

    const forget = async () => {
        if (!window.confirm('Delete all your voice conversations? This cannot be undone.')) {
            return;
        }
        const { deleted_conversations } = await forgetVoiceHistory();
        setNote(`Deleted ${deleted_conversations} voice conversation${deleted_conversations === 1 ? '' : 's'}.`);
    };

    return (
        <>
            <Head title="Voice settings" />
            <h1 className="sr-only">Voice settings</h1>

            <div className="space-y-8">
                <section className="space-y-4">
                    <Heading variant="small" title="How Home Helper sounds" description={voice.driver === 'browser' ? 'This server has no voice service, so your browser listens and speaks with its own voices.' : 'Speech is recognised and spoken on your own server: recordings never leave it and are never stored.'} />

                    <div className="grid gap-2">
                        <Label htmlFor="voice">Voice</Label>
                        <div className="flex gap-2">
                            <select id="voice" className={field} value={settings.voice} disabled={voice.driver === 'browser'} onChange={(e) => save({ voice: e.target.value })}>
                                {voice.voices.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label}
                                    </option>
                                ))}
                            </select>
                            <Button type="button" variant="outline" onClick={tryVoice}>
                                <Play /> Try it
                            </Button>
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">How it answers out loud</legend>
                        {voice.styles.map((s) => (
                            <label key={s.id} className="flex items-center gap-2 text-sm">
                                <input type="radio" name="style" value={s.id} checked={settings.style === s.id} onChange={() => save({ style: s.id })} />
                                {s.label}
                            </label>
                        ))}
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="rate">Speed: {settings.rate.toFixed(2)}×</Label>
                        <input id="rate" type="range" min={0.5} max={2} step={0.05} value={settings.rate} onChange={(e) => setSettings((s) => ({ ...s, rate: Number(e.target.value) }))} onMouseUp={() => save({ rate: settings.rate })} onKeyUp={() => save({ rate: settings.rate })} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="language">Language you speak</Label>
                        <select id="language" className={field} value={settings.language ?? ''} onChange={(e) => save({ language: e.target.value || null })}>
                            <option value="">Detect it</option>
                            <option value="en">English</option>
                            <option value="el">Greek</option>
                        </select>
                    </div>
                </section>

                <section className="space-y-4">
                    <Heading variant="small" title="Listening" description="When the microphone is open, a red dot shows on the assistant page. Nothing listens while the page is in the background." />
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={settings.wake_word} disabled={!voice.wake.available} onChange={(e) => save({ wake_word: e.target.checked })} />
                        <span>
                            Wake word: say “{voice.wake.phrase}” to start talking.
                            <span className="block text-xs text-muted-foreground">
                                {voice.wake.available
                                    ? 'While the assistant page is open, audio goes to your own voice service, which only listens for the wake word: nothing is transcribed or kept until it hears it.'
                                    : 'Needs the voice service (VOICE_DRIVER=service).'}
                            </span>
                        </span>
                    </label>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={settings.continuous} onChange={(e) => save({ continuous: e.target.checked })} />
                        <span>
                            Keep listening after an answer, for a follow-up without the wake word
                            <span className="block text-xs text-muted-foreground">For six seconds; if you say nothing, it stops.</span>
                        </span>
                    </label>
                </section>

                <section className="space-y-4">
                    <Heading variant="small" title="Privacy" description="What is kept of what you say." />
                    <div className="grid gap-2">
                        <Label htmlFor="keep">Keep voice conversations</Label>
                        <select id="keep" className={field} value={settings.keep_days ?? ''} onChange={(e) => save({ keep_days: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">Like typed ones</option>
                            {voice.keep_days_options.map((d) => (
                                <option key={d} value={d}>
                                    For {d} day{d === 1 ? '' : 's'}, then delete them
                                </option>
                            ))}
                        </select>
                    </div>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={settings.remember} onChange={(e) => save({ remember: e.target.checked })} />
                        <span>
                            Let Home Helper remember voice conversations
                            <span className="block text-xs text-muted-foreground">Off: they are never summarised into its long-term memory.</span>
                        </span>
                    </label>
                    <Button type="button" variant="destructive" onClick={forget}>
                        <Trash2 /> Delete my voice conversations
                    </Button>
                </section>

                {note && (
                    <p className="text-sm text-muted-foreground" role="status">
                        {note}
                    </p>
                )}
            </div>
        </>
    );
}

VoiceSettings.layout = {
    breadcrumbs: [{ title: 'Voice settings', href: editVoice() }],
};
