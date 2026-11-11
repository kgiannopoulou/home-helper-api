/**
 * The page's JSON calls for voice (routes/web.php, /voice/*): the session
 * cookie and Laravel's XSRF-TOKEN cookie, like an Inertia request.
 */
import type { VoiceConfig, VoiceTurn } from '@/types/voice';

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class VoiceRequestError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code: string | null,
    ) {
        super(message);
    }
}

async function send<T>(
    url: string,
    init: RequestInit & { json?: unknown } = {},
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf(),
    };
    let body = init.body;
    if (init.json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(init.json);
    }
    const response = await fetch(url, {
        ...init,
        body,
        headers,
        credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new VoiceRequestError(
            data.message ?? `The server answered ${response.status}.`,
            response.status,
            data.code ?? null,
        );
    }

    return data as T;
}

export function newTurnId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `t-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** A recording (or what the browser heard) → the turn */
export function sendTurn(
    input: { audio: Blob } | { text: string },
    turnId: string,
    conversationId: string | null,
    signal?: AbortSignal,
): Promise<VoiceTurn> {
    const form = new FormData();
    if ('audio' in input) {
        const type = input.audio.type || 'audio/webm';
        const ext = type.includes('ogg') ? 'ogg' : type.includes('mp4') ? 'm4a' : 'webm';
        form.append('audio', input.audio, `speech.${ext}`);
    } else {
        form.append('text', input.text);
    }
    form.append('turn_id', turnId);
    if (conversationId) {
        form.append('conversation_id', conversationId);
    }

    return send<VoiceTurn>('/voice/turns', { method: 'POST', body: form, signal });
}

export function cancelTurn(turnId: string): Promise<unknown> {
    return send(`/voice/turns/${encodeURIComponent(turnId)}/cancel`, {
        method: 'POST',
    });
}

export function speak(
    text: string,
    voice?: string,
    rate?: number,
): Promise<{ audio_url: string | null }> {
    return send('/voice/speak', { method: 'POST', json: { text, voice, rate } });
}

export function loadConfig(): Promise<VoiceConfig> {
    return send<VoiceConfig>('/voice/config');
}

export function saveSettings(
    settings: Partial<VoiceConfig['settings']>,
): Promise<VoiceConfig> {
    return send<VoiceConfig>('/settings/voice', {
        method: 'PATCH',
        json: settings,
    });
}

export function forgetVoiceHistory(): Promise<{ deleted_conversations: number }> {
    return send('/settings/voice/history', { method: 'DELETE' });
}
