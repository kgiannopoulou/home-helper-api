import type { ReactNode } from 'react';
import { useState } from 'react';
import { vi } from 'vitest';

/**
 * A stand-in for @inertiajs/react in component tests: no server, no router.
 * Pages get their shared props from `page.props`, and every form's post() is
 * recorded in `posts`, so a test can check what a button would send.
 */
export const page: { props: Record<string, unknown> } = { props: {} };
export const posts: { url: string; data: unknown }[] = [];

export const inertiaMock = {
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
    usePage: () => page,
    router: { post: vi.fn(), on: vi.fn(() => () => {}) },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setAll] = useState<T>(initial);

        return {
            data,
            errors: {} as Partial<Record<keyof T, string>>,
            processing: false,
            setData: (key: keyof T, value: unknown) =>
                setAll((d) => ({ ...d, [key]: value })),
            reset: () => setAll(initial),
            post: (url: string) => posts.push({ url, data }),
        };
    },
};
