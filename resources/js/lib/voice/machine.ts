/**
 * The voice conversation as a state machine (docs/voice.md#the-conversation-loop).
 *
 *   off ──press──▶ listening ──silence/stop──▶ thinking ──reply──▶ speaking ──done──▶ (follow-up) listening
 *    ▲               ▲                                                │                     │ nothing said
 *    │  wake on      │ wake word                                      │ you speak: interrupt │
 *    └──── waiting ◀─┴────────────────────────────────────────────────┴─────────────────────┘
 *
 * Pure: the hook (use-voice.ts) turns microphone, network and audio events
 * into these events and acts on the state, so every rule is testable here.
 */

export type VoiceMode = 'off' | 'waiting' | 'listening' | 'thinking' | 'speaking';

export type VoiceState = {
    mode: VoiceMode;
    /** Listening for the wake word when idle */
    wake: boolean;
    /** After an answer, listen for a follow-up without the wake word */
    continuous: boolean;
    /** This listening is the follow-up window: nothing said = back to idle */
    followUp: boolean;
    /** The turn on its way, so it can be cancelled */
    turnId: string | null;
    /** Muted: nothing listens at all, whatever else is on */
    muted: boolean;
    error: string | null;
};

export type VoiceEvent =
    | { type: 'PRESS' }
    | { type: 'WAKE' }
    | { type: 'SPEECH_END' }
    | { type: 'STOP' }
    | { type: 'SENT'; turnId: string }
    | { type: 'REPLY'; speak: boolean }
    | { type: 'SPOKEN' }
    | { type: 'NOTHING_SAID' }
    | { type: 'INTERRUPT' }
    | { type: 'CANCEL' }
    | { type: 'ERROR'; message: string }
    | { type: 'SET_WAKE'; on: boolean }
    | { type: 'SET_CONTINUOUS'; on: boolean }
    | { type: 'MUTE'; on: boolean };

export function initialVoiceState(wake = false, continuous = true): VoiceState {
    return {
        mode: wake ? 'waiting' : 'off',
        wake,
        continuous,
        followUp: false,
        turnId: null,
        muted: false,
        error: null,
    };
}

/** Where the machine rests: waiting for the wake word, or off */
function idle(s: VoiceState): VoiceState {
    return {
        ...s,
        mode: s.wake && !s.muted ? 'waiting' : 'off',
        followUp: false,
        turnId: null,
    };
}

export function voiceReducer(s: VoiceState, e: VoiceEvent): VoiceState {
    switch (e.type) {
        case 'PRESS':
            // The mic button: start, or (while listening) send what was said
            if (s.muted) {
                return s;
            }
            if (s.mode === 'listening') {
                return { ...s, mode: 'thinking', followUp: false };
            }
            if (s.mode === 'speaking') {
                return { ...s, mode: 'listening', followUp: false, error: null };
            }
            if (s.mode === 'thinking') {
                return s;
            }

            return { ...s, mode: 'listening', followUp: false, error: null };

        case 'WAKE':
            return s.mode === 'waiting' && !s.muted
                ? { ...s, mode: 'listening', followUp: false, error: null }
                : s;

        case 'SPEECH_END':
        case 'STOP':
            return s.mode === 'listening'
                ? { ...s, mode: 'thinking', followUp: false }
                : s;

        case 'SENT':
            return s.mode === 'thinking' ? { ...s, turnId: e.turnId } : s;

        case 'REPLY':
            if (s.mode !== 'thinking') {
                return s; // cancelled meanwhile: drop the late answer
            }

            return e.speak
                ? { ...s, mode: 'speaking', turnId: null }
                : afterAnswer({ ...s, turnId: null });

        case 'SPOKEN':
            return s.mode === 'speaking' ? afterAnswer(s) : s;

        case 'NOTHING_SAID':
            // The follow-up window closed, or a press with nothing said
            return s.mode === 'listening' ? idle(s) : s;

        case 'INTERRUPT':
            // You talk over the answer: it stops, and you are heard
            return s.mode === 'speaking' && !s.muted
                ? { ...s, mode: 'listening', followUp: false }
                : s;

        case 'CANCEL':
            return idle(s);

        case 'ERROR':
            return { ...idle(s), error: e.message };

        case 'SET_WAKE': {
            const next = { ...s, wake: e.on };

            return s.mode === 'off' || s.mode === 'waiting' ? idle(next) : next;
        }

        case 'SET_CONTINUOUS':
            return { ...s, continuous: e.on };

        case 'MUTE': {
            const next = { ...s, muted: e.on };

            // Muting stops whatever was listening; an answer on its way still arrives
            return e.on && (s.mode === 'listening' || s.mode === 'waiting')
                ? idle(next)
                : !e.on && s.mode === 'off'
                  ? idle(next)
                  : next;
        }
    }
}

function afterAnswer(s: VoiceState): VoiceState {
    return s.continuous && !s.muted
        ? { ...s, mode: 'listening', followUp: true }
        : idle(s);
}

/** Is the microphone open in this state? (the red dot) */
export function micIsOn(s: VoiceState): boolean {
    return s.mode === 'listening' || s.mode === 'waiting' || s.mode === 'speaking';
}
