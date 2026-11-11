import { Form, Head, router } from '@inertiajs/react';
import { FileText, Lock, NotebookPen, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { shortDate } from '@/lib/format';
import { memory as memoryRoute } from '@/routes';
import { destroy as destroyFact, store as storeFact } from '@/routes/memory/facts';
import { destroy as destroyNote, store as storeNote } from '@/routes/memory/notes';
import type { Fact, NoteSummary, SearchHit } from '@/types/memory';

type Props = {
    facts: Fact[];
    notes: NoteSummary[];
    members: { id: number; name: string }[];
    kinds: Fact['kind'][];
    query: string;
    results: SearchHit[] | null;
};

const SOURCE: Record<SearchHit['source_type'], string> = {
    note: 'Note',
    document: 'Document',
    fact: 'Remembered',
    conversation: 'Your conversation',
    summary: 'Summary',
};

const field =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function Memory({
    facts,
    notes,
    members,
    kinds,
    query,
    results,
}: Props) {
    const [q, setQ] = useState(query);
    const [upload, setUpload] = useState(false);

    return (
        <>
            <Head title="Memory" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Memory"
                    description="What Home Helper remembers about your household, and the notes and documents it can search. The assistant uses all of it."
                />

                <form
                    role="search"
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            memoryRoute.url(),
                            q.trim() ? { q } : {},
                            { preserveState: true },
                        );
                    }}
                >
                    <Input
                        aria-label="Search memory"
                        placeholder="Search notes, documents, facts and summaries…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Button type="submit">
                        <Search /> Search
                    </Button>
                </form>

                {results !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {results.length
                                    ? `Found for “${query}”`
                                    : `Nothing found for “${query}”`}
                            </CardTitle>
                            <CardDescription>
                                Searched by meaning and by keywords, best match
                                first.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="flex flex-col gap-4">
                                {results.map((hit) => (
                                    <li
                                        key={`${hit.source_id}-${hit.text.slice(0, 20)}`}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary">
                                                {SOURCE[hit.source_type]}
                                            </Badge>
                                            <span className="font-medium">
                                                {hit.title}
                                            </span>
                                            {hit.occurred_on && (
                                                <span className="text-xs text-muted-foreground">
                                                    {shortDate(hit.occurred_on)}
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 line-clamp-3 text-sm whitespace-pre-line text-muted-foreground">
                                            {hit.text}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>What Home Helper remembers</CardTitle>
                            <CardDescription>
                                Preferences, routines and facts. Every member's
                                assistant knows the shared ones; a private one
                                only yours.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ul className="flex flex-col divide-y">
                                {facts.length === 0 && (
                                    <li className="py-2 text-sm text-muted-foreground">
                                        Nothing yet. Add something below, or
                                        tell the assistant “remember that…”.
                                    </li>
                                )}
                                {facts.map((f) => (
                                    <li
                                        key={f.id}
                                        className="flex items-start justify-between gap-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline">
                                                    {f.kind}
                                                </Badge>
                                                {f.about && (
                                                    <span className="text-xs text-muted-foreground">
                                                        about {f.about.name}
                                                    </span>
                                                )}
                                                {f.private && (
                                                    <Lock
                                                        className="size-3.5"
                                                        aria-label="Private"
                                                    />
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm">
                                                {f.content}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {f.source === 'assistant'
                                                    ? 'Through the assistant'
                                                    : 'Added'}
                                                {f.added_by
                                                    ? ` by ${f.added_by}`
                                                    : ''}
                                                , {shortDate(f.created_at.slice(0, 10))}
                                            </p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Forget “${f.content}”`}
                                            onClick={() =>
                                                router.delete(
                                                    destroyFact.url(f.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </li>
                                ))}
                            </ul>

                            <Form
                                {...storeFact.form()}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="flex flex-col gap-3 rounded-lg border p-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="grid gap-1">
                                                <Label htmlFor="fact-kind">
                                                    Kind
                                                </Label>
                                                <select
                                                    id="fact-kind"
                                                    name="kind"
                                                    className={field}
                                                    defaultValue="preference"
                                                >
                                                    {kinds.map((k) => (
                                                        <option key={k} value={k}>
                                                            {k}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="fact-about">
                                                    About
                                                </Label>
                                                <select
                                                    id="fact-about"
                                                    name="about_user_id"
                                                    className={field}
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        The household
                                                    </option>
                                                    {members.map((m) => (
                                                        <option
                                                            key={m.id}
                                                            value={m.id}
                                                        >
                                                            {m.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="fact-content">
                                                Remember
                                            </Label>
                                            <Input
                                                id="fact-content"
                                                name="content"
                                                placeholder="e.g. The bins go out on Tuesday nights"
                                                maxLength={500}
                                            />
                                            <InputError message={errors.content} />
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    name="private"
                                                    value="1"
                                                />
                                                Only for me
                                            </label>
                                            <Button disabled={processing}>
                                                Remember
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Notes and documents</CardTitle>
                            <CardDescription>
                                Instructions, decisions, manuals. Upload .txt,
                                .md or a PDF with text (up to 5 MB).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ul className="flex flex-col divide-y">
                                {notes.length === 0 && (
                                    <li className="py-2 text-sm text-muted-foreground">
                                        No notes yet.
                                    </li>
                                )}
                                {notes.map((n) => (
                                    <li
                                        key={n.id}
                                        className="flex items-start justify-between gap-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                {n.kind === 'document' ? (
                                                    <FileText className="size-4 shrink-0" />
                                                ) : (
                                                    <NotebookPen className="size-4 shrink-0" />
                                                )}
                                                <span className="truncate font-medium">
                                                    {n.title}
                                                </span>
                                                {n.private && (
                                                    <Lock
                                                        className="size-3.5"
                                                        aria-label="Private"
                                                    />
                                                )}
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                {n.excerpt}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {n.file ? `${n.file.name} · ` : ''}
                                                {n.added_by ?? 'Someone'},{' '}
                                                {shortDate(n.updated_at.slice(0, 10))}
                                            </p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Delete “${n.title}”`}
                                            onClick={() =>
                                                router.delete(
                                                    destroyNote.url(n.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </li>
                                ))}
                            </ul>

                            <Form
                                {...storeNote.form()}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="flex flex-col gap-3 rounded-lg border p-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="flex gap-2 text-sm">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={upload ? 'ghost' : 'secondary'}
                                                onClick={() => setUpload(false)}
                                            >
                                                Write a note
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={upload ? 'secondary' : 'ghost'}
                                                onClick={() => setUpload(true)}
                                            >
                                                Upload a document
                                            </Button>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="note-title">
                                                Title{upload ? ' (optional)' : ''}
                                            </Label>
                                            <Input
                                                id="note-title"
                                                name="title"
                                                maxLength={150}
                                            />
                                            <InputError message={errors.title} />
                                        </div>
                                        {upload ? (
                                            <div className="grid gap-1">
                                                <Label htmlFor="note-file">
                                                    File
                                                </Label>
                                                <Input
                                                    id="note-file"
                                                    name="file"
                                                    type="file"
                                                    accept=".txt,.md,.pdf"
                                                />
                                                <InputError message={errors.file} />
                                            </div>
                                        ) : (
                                            <div className="grid gap-1">
                                                <Label htmlFor="note-body">
                                                    Note
                                                </Label>
                                                <textarea
                                                    id="note-body"
                                                    name="body"
                                                    rows={4}
                                                    className={field}
                                                />
                                                <InputError message={errors.body} />
                                            </div>
                                        )}
                                        <div className="flex items-center justify-between gap-3">
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    name="private"
                                                    value="1"
                                                />
                                                Only for me
                                            </label>
                                            <Button disabled={processing}>
                                                Save
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Memory.layout = {
    breadcrumbs: [{ title: 'Memory', href: memoryRoute() }],
};
