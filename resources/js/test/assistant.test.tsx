import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { RichText } from '@/components/assistant/rich-text';
import { inertiaMock } from '@/test/inertia';
import type { ChatMessage, PendingAction } from '@/types/assistant';
import Assistant from '@/pages/assistant';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const suggestions = [
    'What should I do today?',
    'What do I need to buy?',
    'How much did I spend this month?',
];

const base = {
    conversations: [],
    conversation: null,
    messages: [] as ChatMessage[],
    actions: [] as PendingAction[],
    provider: 'claude',
    suggestions,
};

describe('Assistant page', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('an empty conversation offers the questions; picking one sends it', () => {
        render(<Assistant {...base} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'What do I need to buy?' }),
        );

        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/assistant',
            { message: 'What do I need to buy?' },
            expect.anything(),
        );
    });

    test('Enter sends, into the open conversation; Shift+Enter does not', () => {
        render(
            <Assistant
                {...base}
                conversation={{ id: 'c1', title: 'Shopping', updated_at: null }}
            />,
        );
        const box = screen.getByLabelText('Message');

        fireEvent.change(box, { target: { value: 'And bread?' } });
        fireEvent.keyDown(box, { key: 'Enter', shiftKey: true });
        expect(inertiaMock.router.post).not.toHaveBeenCalled();

        fireEvent.keyDown(box, { key: 'Enter' });
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/assistant/c1/messages',
            { message: 'And bread?' },
            expect.anything(),
        );
    });

    test('an answer shows what it looked at, and a proposal has Confirm and Cancel', () => {
        render(
            <Assistant
                {...base}
                conversation={{ id: 'c1', title: 'Shopping', updated_at: null }}
                messages={[
                    {
                        id: 1,
                        role: 'user',
                        text: 'What do I need to buy?',
                        tools: [],
                        created_at: null,
                    },
                    {
                        id: 2,
                        role: 'assistant',
                        text: 'To buy:\n- Milk\n- **Bread**',
                        tools: ['Read the shopping list'],
                        created_at: null,
                    },
                ]}
                actions={[
                    {
                        id: 'a1',
                        summary: 'Add "Eggs" to the shopping list',
                        tool: 'add_shopping_item',
                        status: 'pending',
                        expires_at: '',
                    },
                    {
                        id: 'a2',
                        summary: 'Old one',
                        tool: 'add_todo',
                        status: 'declined',
                        expires_at: '',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Read the shopping list')).toBeInTheDocument();
        expect(screen.getByRole('list')).toHaveTextContent('MilkBread');
        expect(screen.queryByText('Old one')).not.toBeInTheDocument();

        const card = screen
            .getByText(/Add "Eggs"/)
            .closest('div')!.parentElement!;
        fireEvent.click(within(card).getByRole('button', { name: /Confirm/ }));
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/assistant/actions/a1/confirm',
            {},
            expect.anything(),
        );
    });

    test('offline mode says it has no language model', () => {
        render(<Assistant {...base} provider="offline" />);

        expect(screen.getByText(/no language model/)).toBeInTheDocument();
    });
});

describe('RichText', () => {
    test('paragraphs, a list after a lead line, and bold', () => {
        const { container } = render(
            <RichText text={'Here you go.\n\nTo buy:\n- Milk\n- **Bread**'} />,
        );

        expect(container.querySelectorAll('p')).toHaveLength(2);
        expect(container.querySelectorAll('li')).toHaveLength(2);
        expect(container.querySelector('strong')).toHaveTextContent('Bread');
    });

    test('never renders markup from the text', () => {
        const { container } = render(
            <RichText text={'<img src=x onerror=alert(1)> **<b>hi</b>**'} />,
        );

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('b')).toBeNull();
        expect(container).toHaveTextContent('<img src=x onerror=alert(1)>');
    });
});
