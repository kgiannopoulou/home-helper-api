/** App\Ai\Presenter, as the assistant page receives it */
export type ConversationSummary = {
    id: string;
    title: string;
    updated_at: string | null;
};

export type ChatMessage = {
    id: number;
    role: 'user' | 'assistant' | 'event';
    text: string;
    /** What the assistant looked at for this answer, in words */
    tools: string[];
    created_at: string | null;
};

export type PendingAction = {
    id: string;
    summary: string;
    tool: string;
    status: 'pending' | 'confirmed' | 'declined' | 'expired' | 'failed';
    expires_at: string;
};
