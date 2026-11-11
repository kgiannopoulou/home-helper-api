import type { ChatMessage, ConversationSummary, PendingAction } from './assistant';

/** App\Voice\VoicePresenter::config() */
export type VoiceConfig = {
    driver: 'service' | 'browser' | 'fake';
    settings: {
        voice: string;
        style: 'brief' | 'conversational' | 'detailed' | 'butler';
        rate: number;
        language: string | null;
        wake_word: boolean;
        continuous: boolean;
        keep_days: number | null;
        remember: boolean;
    };
    voices: { id: string; label: string; language: string }[];
    styles: { id: VoiceConfig['settings']['style']; label: string }[];
    keep_days_options: number[];
    wake: { available: boolean; phrase: string; url: string | null };
    max_seconds: number;
};

/** App\Voice\VoicePresenter::turn() */
export type VoiceTurn = {
    turn_id: string;
    heard: string;
    language: string | null;
    reply: string;
    spoken: string;
    audio_url: string | null;
    cancelled: boolean;
    conversation: ConversationSummary | null;
    messages: ChatMessage[];
    actions: PendingAction[];
};
