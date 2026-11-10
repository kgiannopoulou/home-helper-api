import type { ReactNode } from 'react';

const BULLET = /^\s*[-•*]\s+/;

/**
 * The assistant's plain-text answers, made readable: paragraphs, "- " lists
 * (optionally after a lead line) and **bold**. Built from React elements,
 * never from HTML, so nothing the model writes, or a household member typed
 * into an item name, can inject markup.
 */
export function RichText({ text }: { text: string }) {
    return (
        <div className="space-y-2">
            {text
                .trim()
                .split(/\n{2,}/)
                .map((block, i) => {
                    const lines = block.split('\n');
                    const lead = BULLET.test(lines[0]) ? null : lines[0];
                    const rest = lead === null ? lines : lines.slice(1);

                    if (rest.length > 0 && rest.every((l) => BULLET.test(l))) {
                        return (
                            <div key={i}>
                                {lead && <p>{inline(lead)}</p>}
                                <ul className="ml-5 list-disc space-y-0.5">
                                    {rest.map((item, j) => (
                                        <li key={j}>
                                            {inline(item.replace(BULLET, ''))}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    }

                    return (
                        <p key={i} className="whitespace-pre-line">
                            {inline(block)}
                        </p>
                    );
                })}
        </div>
    );
}

function inline(text: string): ReactNode[] {
    return text
        .split(/(\*\*[^*]+\*\*)/g)
        .map((part, i) =>
            part.startsWith('**') && part.endsWith('**') && part.length > 4 ? (
                <strong key={i}>{part.slice(2, -2)}</strong>
            ) : (
                part
            ),
        );
}
