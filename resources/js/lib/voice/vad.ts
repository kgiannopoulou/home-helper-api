/**
 * Voice activity detection by loudness (docs/voice.md#knowing-when-you-stopped).
 *
 * Feed it the microphone's level (RMS, 0–1) every frame. It learns the room's
 * background level, says "speech-start" once the level stays clearly above it
 * for a moment, and "speech-end" after a pause, so you don't press stop.
 * Pure and deterministic: time is passed in.
 */

export type VadOptions = {
    /** Speech is this many times louder than the background */
    ratio: number;
    /** …and at least this loud, so a silent room isn't "speech" */
    minLevel: number;
    /** Loud for this long before it counts as speech (a click isn't) */
    startMs: number;
    /** Quiet for this long after speech = finished */
    silenceMs: number;
};

export type VadEvent = 'speech-start' | 'speech-end' | null;

export const DEFAULT_VAD: VadOptions = {
    ratio: 3,
    minLevel: 0.015,
    startMs: 120,
    silenceMs: 900,
};

export class VoiceActivity {
    private noise = 0.01;
    private speaking = false;
    private loudSince: number | null = null;
    private quietSince: number | null = null;

    constructor(private readonly o: VadOptions = DEFAULT_VAD) {}

    get isSpeaking(): boolean {
        return this.speaking;
    }

    get threshold(): number {
        return Math.max(this.o.minLevel, this.noise * this.o.ratio);
    }

    feed(level: number, now: number): VadEvent {
        const loud = level > this.threshold;

        if (!this.speaking) {
            // The background drifts: follow it slowly, only while nobody speaks
            if (!loud) {
                this.noise = this.noise * 0.95 + level * 0.05;
                this.loudSince = null;

                return null;
            }
            this.loudSince ??= now;
            if (now - this.loudSince >= this.o.startMs) {
                this.speaking = true;
                this.quietSince = null;

                return 'speech-start';
            }

            return null;
        }

        if (loud) {
            this.quietSince = null;

            return null;
        }
        this.quietSince ??= now;
        if (now - this.quietSince >= this.o.silenceMs) {
            this.speaking = false;
            this.loudSince = null;

            return 'speech-end';
        }

        return null;
    }

    reset(): void {
        this.speaking = false;
        this.loudSince = null;
        this.quietSince = null;
    }
}

/** The RMS level of a frame of samples (-1…1) */
export function rms(samples: Float32Array): number {
    let sum = 0;
    for (let i = 0; i < samples.length; i++) {
        sum += samples[i] * samples[i];
    }

    return samples.length ? Math.sqrt(sum / samples.length) : 0;
}
