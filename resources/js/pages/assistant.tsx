import { Head, Link, router } from '@inertiajs/react';
import { Bot, Check, MessageSquarePlus, Send, Trash2, X } from 'lucide-react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { RichText } from '@/components/assistant/rich-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { assistant as assistantRoute } from '@/routes';
import { destroy, message, show, start } from '@/routes/assistant';
import { confirm, decline } from '@/routes/assistant/actions';
import type {
    ChatMessage,
    ConversationSummary,
    PendingAction,
} from '@/types/assistant';

type Props = {
    conversations: ConversationSummary[];
    conversation: ConversationSummary | null;
    messages: ChatMessage[];
    actions: PendingAction[];
    provider: string;
    suggestions: string[];
};

export default function Assistant({
    conversations,
    conversation,
    messages,
    actions,
    provider,
    suggestions,
}: Props) {
    const [draft, setDraft] = useState('');
    /** The message on its way, shown until the answer arrives */
    const [sending, setSending] = useState<string | null>(null);
    const bottom = useRef<HTMLDivElement>(null);
    const open = actions.filter((a) => a.status === 'pending');

    useEffect(() => {
        bottom.current?.scrollIntoView?.({ block: 'end' });
    }, [messages.length, sending]);

    const send = (text: string) => {
        if (!text.trim() || sending !== null) {
            return;
        }
        const url = conversation ? message.url(conversation.id) : start.url();
        router.post(
            url,
            { message: text },
            {
                preserveScroll: true,
                onStart: () => {
                    setSending(text);
                    setDraft('');
                },
                onFinish: () => setSending(null),
            },
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        send(draft);
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send(draft);
        }
    };

    return (
        <>
            <Head title="Assistant" />
            <div className="flex h-[calc(100svh-5rem)] gap-6 p-4 md:p-6">
                <aside className="hidden w-64 shrink-0 flex-col gap-2 lg:flex">
                    <Button asChild variant="outline" className="justify-start">
                        <Link href={assistantRoute()}>
                            <MessageSquarePlus /> New conversation
                        </Link>
                    </Button>
                    <nav
                        aria-label="Conversations"
                        className="flex flex-col gap-1 overflow-y-auto"
                    >
                        {conversations.map((c) => (
                            <Link
                                key={c.id}
                                href={show(c.id)}
                                className={cn(
                                    'truncate rounded-md px-3 py-2 text-sm hover:bg-accent',
                                    c.id === conversation?.id &&
                                        'bg-accent font-medium',
                                )}
                            >
                                {c.title}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <section className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center justify-between gap-2 pb-3">
                        <div className="min-w-0">
                            <h1 className="truncate text-lg font-semibold">
                                {conversation?.title ?? 'Ask Home Helper'}
                            </h1>
                            {provider === 'offline' && (
                                <p className="text-xs text-muted-foreground">
                                    Offline mode: no language model, only the
                                    common questions are understood.
                                </p>
                            )}
                        </div>
                        {conversation && (
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Delete this conversation"
                                onClick={() =>
                                    router.delete(destroy.url(conversation.id))
                                }
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </header>

                    <div
                        className="flex-1 space-y-4 overflow-y-auto pr-1"
                        aria-live="polite"
                    >
                        {messages.length === 0 && sending === null && (
                            <EmptyState
                                suggestions={suggestions}
                                onPick={send}
                            />
                        )}

                        {messages.map((m) => (
                            <Message key={m.id} message={m} />
                        ))}

                        {sending !== null && (
                            <>
                                <Message
                                    message={{
                                        id: -1,
                                        role: 'user',
                                        text: sending,
                                        tools: [],
                                        created_at: null,
                                    }}
                                />
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Spinner /> Looking it up…
                                </p>
                            </>
                        )}

                        {open.map((a) => (
                            <ActionCard key={a.id} action={a} />
                        ))}
                        <div ref={bottom} />
                    </div>

                    <form onSubmit={submit} className="flex gap-2 pt-3">
                        <label htmlFor="assistant-message" className="sr-only">
                            Message
                        </label>
                        <textarea
                            id="assistant-message"
                            rows={2}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={onKeyDown}
                            maxLength={4000}
                            placeholder="Ask about shopping, chores, money, plants…"
                            className="min-h-11 flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            disabled={sending !== null}
                        />
                        <Button
                            type="submit"
                            disabled={sending !== null || !draft.trim()}
                            aria-label="Send"
                        >
                            <Send />
                        </Button>
                    </form>
                </section>
            </div>
        </>
    );
}

function EmptyState({
    suggestions,
    onPick,
}: {
    suggestions: string[];
    onPick: (text: string) => void;
}) {
    return (
        <div className="mx-auto flex max-w-xl flex-col items-center gap-4 py-12 text-center">
            <Bot className="size-10 text-muted-foreground" aria-hidden />
            <div>
                <h2 className="text-xl font-semibold">Ask Home Helper</h2>
                <p className="text-sm text-muted-foreground">
                    It reads your household&apos;s lists, chores, money and
                    plants. Anything it wants to change waits for you to
                    confirm.
                </p>
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                {suggestions.map((s) => (
                    <Button
                        key={s}
                        variant="outline"
                        size="sm"
                        onClick={() => onPick(s)}
                    >
                        {s}
                    </Button>
                ))}
            </div>
        </div>
    );
}

export function Message({ message: m }: { message: ChatMessage }) {
    if (m.role === 'event') {
        return (
            <p className="text-center text-xs text-muted-foreground">
                {m.text}
            </p>
        );
    }

    const mine = m.role === 'user';

    return (
        <div className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[85%] rounded-lg px-4 py-2 text-sm',
                    mine ? 'bg-primary text-primary-foreground' : 'bg-muted',
                )}
            >
                {m.tools.length > 0 && (
                    <div className="mb-1 flex flex-wrap gap-1">
                        {m.tools.map((t) => (
                            <Badge
                                key={t}
                                variant="outline"
                                className="text-xs"
                            >
                                {t}
                            </Badge>
                        ))}
                    </div>
                )}
                {m.text && <RichText text={m.text} />}
            </div>
        </div>
    );
}

export function ActionCard({ action }: { action: PendingAction }) {
    return (
        <Card className="border-dashed">
            <CardContent className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm">
                    <span className="font-medium">Waiting for you:</span>{' '}
                    {action.summary}
                </p>
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                confirm.url(action.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Check /> Confirm
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                decline.url(action.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <X /> Cancel
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

Assistant.layout = {
    breadcrumbs: [{ title: 'Assistant', href: assistantRoute() }],
};
