/**
 * Talking to Home Helper in the browser (docs/voice.md#in-the-browser).
 *
 * The rules live in lib/voice/machine.ts; this hook is the plumbing:
 * the microphone (one stream, echo-cancelled), a level meter and voice
 * activity detection, a MediaRecorder per question (or the browser's own
 * speech recognition with VOICE_DRIVER=browser), the request with its turn id
 * so it can be cancelled, playback of the answer (or speechSynthesis), and
 * the wake-word socket while waiting.
 */
import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { cancelTurn, newTurnId, sendTurn } from '@/lib/voice/api';
import type { VoiceEvent, VoiceState } from '@/lib/voice/machine';
import { initialVoiceState, micIsOn, voiceReducer } from '@/lib/voice/machine';
import { toPcm16 } from '@/lib/voice/pcm';
import { rms, VoiceActivity } from '@/lib/voice/vad';
import type { VoiceConfig, VoiceTurn } from '@/types/voice';

/** Nothing said this long after the mic opened: close it */
const NO_SPEECH_MS = 8000;
/** The follow-up window after an answer is shorter */
const FOLLOW_UP_MS = 6000;

export type Voice = {
    state: VoiceState;
    /** Microphone level 0–1, for the meter */
    level: number;
    /** What was heard last, shown while thinking */
    heard: string;
    supported: boolean;
    press: () => void;
    cancel: () => void;
    setWake: (on: boolean) => void;
    setContinuous: (on: boolean) => void;
    mute: (on: boolean) => void;
};

type SpeechRecognitionLike = {
    lang: string;
    interimResults: boolean;
    continuous: boolean;
    start: () => void;
    stop: () => void;
    abort: () => void;
    onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal: boolean }> }) => void) | null;
    onend: (() => void) | null;
    onerror: ((e: { error: string }) => void) | null;
};

function recognitionClass(): (new () => SpeechRecognitionLike) | null {
    const w = window as unknown as Record<string, unknown>;

    return (w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null) as (new () => SpeechRecognitionLike) | null;
}

export function useVoice(
    config: VoiceConfig,
    conversationId: string | null,
    onTurn: (turn: VoiceTurn) => void,
): Voice {
    const browserMode = config.driver === 'browser';
    const [state, dispatch] = useReducer(
        voiceReducer,
        initialVoiceState(config.wake.available && config.settings.wake_word, config.settings.continuous),
    );
    const [level, setLevel] = useState(0);
    const [heard, setHeard] = useState('');

    const supported =
        typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices?.getUserMedia &&
        (browserMode ? recognitionClass() !== null : typeof MediaRecorder !== 'undefined');

    // Everything the event handlers need, without re-subscribing them
    const r = useRef({
        stream: null as MediaStream | null,
        context: null as AudioContext | null,
        analyser: null as AnalyserNode | null,
        recorder: null as MediaRecorder | null,
        chunks: [] as Blob[],
        recognition: null as SpeechRecognitionLike | null,
        recognised: '',
        vad: new VoiceActivity(),
        listeningSince: 0,
        spoke: false,
        abort: null as AbortController | null,
        audio: null as HTMLAudioElement | null,
        socket: null as WebSocket | null,
        tap: null as ScriptProcessorNode | null,
        mode: state.mode,
        followUp: state.followUp,
        hiddenMuted: false,
        turnId: state.turnId,
        conversationId,
        onTurn,
    });
    r.current.mode = state.mode;
    r.current.followUp = state.followUp;
    r.current.turnId = state.turnId;
    r.current.conversationId = conversationId;
    r.current.onTurn = onTurn;

    const send = useCallback((e: VoiceEvent) => dispatch(e), []);

    // --- the microphone: open while anything listens, closed otherwise (privacy)
    const needMic = micIsOn(state) && !state.muted;
    useEffect(() => {
        if (!needMic || !supported) {
            return;
        }
        let stopped = false;
        let timer = 0;
        (async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                });
                if (stopped) {
                    stream.getTracks().forEach((t) => t.stop());

                    return;
                }
                const context = new AudioContext();
                const analyser = context.createAnalyser();
                analyser.fftSize = 1024;
                context.createMediaStreamSource(stream).connect(analyser);
                Object.assign(r.current, { stream, context, analyser });

                const buffer = new Float32Array(analyser.fftSize);
                timer = window.setInterval(() => {
                    analyser.getFloatTimeDomainData(buffer);
                    const now = performance.now();
                    const lvl = rms(buffer);
                    setLevel(lvl);
                    const mode = r.current.mode;
                    if (mode !== 'listening' && mode !== 'speaking') {
                        return;
                    }
                    // While it speaks, only a clearly louder voice counts (the echo of the answer must not)
                    const event = r.current.vad.feed(mode === 'speaking' ? lvl / 2 : lvl, now);
                    if (mode === 'speaking' && event === 'speech-start') {
                        send({ type: 'INTERRUPT' });
                    } else if (mode === 'listening') {
                        if (event === 'speech-start') {
                            r.current.spoke = true;
                        } else if (event === 'speech-end' && !browserMode) {
                            send({ type: 'SPEECH_END' });
                        } else if (!r.current.spoke && now - r.current.listeningSince > (r.current.followUp ? FOLLOW_UP_MS : NO_SPEECH_MS)) {
                            send({ type: 'NOTHING_SAID' });
                        } else if (now - r.current.listeningSince > config.max_seconds * 1000) {
                            send({ type: 'STOP' });
                        }
                    }
                }, 50);
            } catch {
                send({ type: 'ERROR', message: 'The microphone is not available. Allow it in the browser to talk.' });
            }
        })();

        return () => {
            stopped = true;
            window.clearInterval(timer);
            r.current.stream?.getTracks().forEach((t) => t.stop());
            void r.current.context?.close();
            Object.assign(r.current, { stream: null, context: null, analyser: null });
            setLevel(0);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [needMic, supported, browserMode, send, config.max_seconds]);

    // --- listening: record (or recognise) one question
    useEffect(() => {
        if (state.mode !== 'listening' || state.muted) {
            return;
        }
        const ref = r.current;
        ref.vad.reset();
        ref.spoke = false;
        ref.listeningSince = performance.now();
        ref.chunks = [];
        ref.recognised = '';
        setHeard('');

        if (browserMode) {
            const Recognition = recognitionClass();
            if (!Recognition) {
                return;
            }
            const recognition = new Recognition();
            recognition.lang = config.settings.language ?? navigator.language;
            recognition.interimResults = true;
            recognition.continuous = false;
            recognition.onresult = (e) => {
                const text = Array.from(e.results).map((res) => res[0].transcript).join(' ');
                ref.recognised = text;
                ref.spoke = true;
                setHeard(text);
            };
            recognition.onend = () => {
                if (r.current.mode === 'listening') {
                    send(ref.recognised.trim() ? { type: 'STOP' } : { type: 'NOTHING_SAID' });
                }
            };
            recognition.onerror = (e) => {
                if (e.error !== 'no-speech' && e.error !== 'aborted') {
                    send({ type: 'ERROR', message: `Speech recognition failed: ${e.error}.` });
                }
            };
            ref.recognition = recognition;
            recognition.start();

            return () => recognition.abort();
        }

        // Wait for the stream the mic effect opens
        let recorder: MediaRecorder | null = null;
        const start = window.setInterval(() => {
            if (!ref.stream || recorder) {
                return;
            }
            window.clearInterval(start);
            const type = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find((t) => MediaRecorder.isTypeSupported?.(t));
            recorder = new MediaRecorder(ref.stream, type ? { mimeType: type } : undefined);
            recorder.ondataavailable = (e) => e.data.size && ref.chunks.push(e.data);
            recorder.start(250);
            ref.recorder = recorder;
        }, 20);

        return () => {
            window.clearInterval(start);
            // Leaving "listening" ends the recording; "thinking" sends it, anything else drops it
            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            }
        };
    }, [state.mode, state.muted, browserMode, config.settings.language, send]);

    // --- thinking: send the question, then answer
    useEffect(() => {
        if (state.mode !== 'thinking') {
            return;
        }
        const ref = r.current;
        const turnId = newTurnId();
        const abort = new AbortController();
        ref.abort = abort;
        send({ type: 'SENT', turnId });

        const collect = (): Promise<{ audio: Blob } | { text: string } | null> => {
            if (browserMode) {
                ref.recognition?.stop();
                const text = ref.recognised.trim();

                return Promise.resolve(text ? { text } : null);
            }
            const recorder = ref.recorder;

            return new Promise((resolve) => {
                const done = () => resolve(ref.chunks.length ? { audio: new Blob(ref.chunks, { type: recorder?.mimeType || 'audio/webm' }) } : null);
                if (recorder && recorder.state !== 'inactive') {
                    recorder.addEventListener('stop', done, { once: true });
                    recorder.stop();
                } else {
                    done();
                }
            });
        };

        collect()
            .then((input) => {
                if (!input) {
                    send({ type: 'CANCEL' });

                    return;
                }

                return sendTurn(input, turnId, ref.conversationId, abort.signal).then((turn) => {
                    if (abort.signal.aborted || turn.cancelled) {
                        return;
                    }
                    setHeard(turn.heard);
                    ref.onTurn(turn);
                    send({ type: 'REPLY', speak: !!(turn.audio_url || turn.spoken) });
                    play(turn);
                });
            })
            .catch((e: Error) => {
                if (!abort.signal.aborted) {
                    send({ type: 'ERROR', message: e.message });
                }
            });

        function play(turn: VoiceTurn) {
            const finished = () => send({ type: 'SPOKEN' });
            if (turn.audio_url) {
                const audio = new Audio(turn.audio_url);
                ref.audio = audio;
                audio.onended = finished;
                audio.onerror = finished;
                void audio.play().catch(finished);
            } else if (turn.spoken && 'speechSynthesis' in window) {
                const u = new SpeechSynthesisUtterance(turn.spoken);
                u.rate = config.settings.rate;
                u.lang = config.settings.language ?? turn.language ?? 'en-GB';
                u.onend = finished;
                u.onerror = finished;
                window.speechSynthesis.speak(u);
            } else {
                finished();
            }
        }
        // Only on entering "thinking"
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [state.mode === 'thinking']);

    // --- leaving "speaking" early (interrupted, cancelled, muted): silence at once
    useEffect(() => {
        if (state.mode === 'speaking') {
            return;
        }
        r.current.audio?.pause();
        r.current.audio = null;
        if (typeof window !== 'undefined' && 'speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }, [state.mode]);

    // --- waiting: stream 16 kHz audio to the wake-word detector, nothing else
    useEffect(() => {
        if (state.mode !== 'waiting' || state.muted || !config.wake.url) {
            return;
        }
        let closed = false;
        const socket = new WebSocket(config.wake.url);
        socket.binaryType = 'arraybuffer';
        r.current.socket = socket;
        socket.onmessage = (m) => {
            const data = JSON.parse(String(m.data)) as { event: string };
            if (data.event === 'wake') {
                chime();
                send({ type: 'WAKE' });
            }
        };
        socket.onclose = (e) => {
            if (!closed && e.code === 4401) {
                send({ type: 'ERROR', message: 'The wake word needs a fresh start: reload the page.' });
            }
        };

        const attach = window.setInterval(() => {
            const { context, stream } = r.current;
            if (!context || !stream || r.current.tap) {
                return;
            }
            window.clearInterval(attach);
            const tap = context.createScriptProcessor(4096, 1, 1);
            tap.onaudioprocess = (e) => {
                if (socket.readyState === WebSocket.OPEN) {
                    socket.send(toPcm16(e.inputBuffer.getChannelData(0), context.sampleRate).buffer);
                }
            };
            context.createMediaStreamSource(stream).connect(tap);
            tap.connect(context.destination);
            r.current.tap = tap;
        }, 50);

        return () => {
            closed = true;
            window.clearInterval(attach);
            r.current.tap?.disconnect();
            r.current.tap = null;
            socket.close();
            r.current.socket = null;
        };
    }, [state.mode, state.muted, config.wake.url, send]);

    // --- privacy: nothing listens while the tab is hidden
    useEffect(() => {
        // Unmute on return only if it was the hiding that muted it
        const onVisibility = () => {
            if (document.hidden && !r.current.hiddenMuted) {
                r.current.hiddenMuted = true;
                send({ type: 'MUTE', on: true });
            } else if (!document.hidden && r.current.hiddenMuted) {
                r.current.hiddenMuted = false;
                send({ type: 'MUTE', on: false });
            }
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, [send]);

    const cancel = useCallback(() => {
        const ref = r.current;
        ref.abort?.abort();
        if (ref.turnId) {
            void cancelTurn(ref.turnId).catch(() => undefined);
        }
        send({ type: 'CANCEL' });
    }, [send]);

    return {
        state,
        level,
        heard,
        supported,
        press: () => send({ type: 'PRESS' }),
        cancel,
        setWake: (on) => send({ type: 'SET_WAKE', on }),
        setContinuous: (on) => send({ type: 'SET_CONTINUOUS', on }),
        mute: (on) => send({ type: 'MUTE', on }),
    };
}

/** A short two-note chime: "I heard my name" */
function chime(): void {
    try {
        const context = new AudioContext();
        [660, 880].forEach((hz, i) => {
            const osc = context.createOscillator();
            const gain = context.createGain();
            osc.frequency.value = hz;
            gain.gain.setValueAtTime(0.08, context.currentTime + i * 0.12);
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + i * 0.12 + 0.11);
            osc.connect(gain).connect(context.destination);
            osc.start(context.currentTime + i * 0.12);
            osc.stop(context.currentTime + i * 0.12 + 0.12);
        });
        window.setTimeout(() => void context.close(), 400);
    } catch {
        // No audio output: the visual state is enough
    }
}
