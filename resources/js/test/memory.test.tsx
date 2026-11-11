import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import Briefing, { sections } from '@/pages/briefing';
import Memory from '@/pages/memory';
import { inertiaMock, posts } from '@/test/inertia';
import type { Briefing as BriefingData, Fact } from '@/types/memory';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const fact: Fact = {
    id: 'f1',
    kind: 'preference',
    content: "Doesn't eat meat.",
    about: { id: 2, name: 'Alex' },
    added_by: 'Maria',
    source: 'assistant',
    private: false,
    mine: true,
    created_at: '2026-11-01T10:00:00+02:00',
};

const briefing: BriefingData = {
    date: '2026-11-11',
    weekday: 'Wednesday',
    for: 'Maria',
    currency: 'EUR',
    headline: '2 things due · Dentist at 10:30',
    weather: null,
    weather_note: 'The household has no location set.',
    calendar: { today: [{ title: 'Dentist', starts: '2026-11-11 10:30' }], tomorrow: [] },
    tasks: {
        todos: [{ id: 't1', title: 'Call the plumber', overdue: true }],
        chores_due: [],
        chores_overdue: [{ id: 'c1', name: 'Clean the oven', room: 'Kitchen', days_overdue: 5, yours: true }],
    },
    shopping: null,
    kitchen: null,
    money: { spent_yesterday: 23.4, month_to_date: 123.4, budget: 1650, forecast: 400, over_budget: null },
    bills: [],
    plants: { to_water: [{ name: 'Basil', amount_ml: 150, skip_for_rain: true }], other_care: [], weather_warnings: [] },
    maintenance: null,
    recommendations: [
        {
            key: 'expiring_food:Spinach:2026-11-11',
            rule: 'expiring_food',
            title: 'Use Spinach first',
            reason: 'expiring by Thu 12 Nov',
            ask: 'Suggest a dinner that uses Spinach.',
            priority: 60,
        },
    ],
    unavailable: [],
};

describe('Memory page', () => {
    beforeEach(() => {
        posts.length = 0;
        vi.mocked(inertiaMock.router.get).mockClear();
        vi.mocked(inertiaMock.router.delete).mockClear();
    });

    test('lists what is remembered, and forgets one with its button', () => {
        render(<Memory facts={[fact]} notes={[]} members={[{ id: 2, name: 'Alex' }]} kinds={['fact', 'preference', 'routine']} query="" results={null} />);

        expect(screen.getByText("Doesn't eat meat.")).toBeTruthy();
        expect(screen.getByText('about Alex')).toBeTruthy();
        expect(screen.getByText(/Through the assistant by Maria/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: "Forget “Doesn't eat meat.”" }));
        expect(inertiaMock.router.delete).toHaveBeenCalledWith('/memory/facts/f1', expect.anything());
    });

    test('a search goes to the server, and its results say where they came from', () => {
        const { rerender } = render(<Memory facts={[]} notes={[]} members={[]} kinds={['fact']} query="" results={null} />);
        fireEvent.change(screen.getByLabelText('Search memory'), { target: { value: 'boiler' } });
        fireEvent.submit(screen.getByRole('search'));
        expect(inertiaMock.router.get).toHaveBeenCalledWith('/memory', { q: 'boiler' }, expect.anything());

        rerender(
            <Memory
                facts={[]}
                notes={[]}
                members={[]}
                kinds={['fact']}
                query="boiler"
                results={[{ source_type: 'note', source_id: 'n1', title: 'Boiler: low pressure reset', text: 'Open both taps until 1.5 bar.', occurred_on: '2026-09-01', score: 32, matched_by: ['meaning'] }]}
            />,
        );
        expect(screen.getByText('Found for “boiler”')).toBeTruthy();
        expect(screen.getAllByText('Note')).toHaveLength(2); // the result's badge and the form's label
        expect(screen.getByText('Open both taps until 1.5 bar.')).toBeTruthy();
    });

    test('adding a fact posts what was typed', () => {
        render(<Memory facts={[]} notes={[]} members={[{ id: 2, name: 'Alex' }]} kinds={['fact', 'preference', 'routine']} query="" results={null} />);

        fireEvent.change(screen.getByLabelText('Kind'), { target: { value: 'routine' } });
        fireEvent.change(screen.getByLabelText('Remember'), { target: { value: 'Bins on Tuesday nights' } });
        fireEvent.click(screen.getByRole('button', { name: 'Remember' }));

        expect(posts).toEqual([{ url: '/memory/facts', data: { kind: 'routine', about_user_id: '', content: 'Bins on Tuesday nights' } }]);
    });
});

describe('Today page', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test('the briefing in sections, with only what has something in it', () => {
        expect(sections(briefing).map((s) => s.title)).toEqual(['Weather', 'Calendar', 'Tasks', 'Money', 'Plants']);
        expect(sections(briefing).find((s) => s.title === 'Tasks')?.lines).toEqual([
            'Call the plumber (overdue)',
            'Kitchen: Clean the oven, 5 days late (yours)',
        ]);
    });

    test('a recommendation can go to the assistant, or get feedback', () => {
        render(<Briefing briefing={briefing} timeline={[]} kinds={['chore']} filters={{ kind: null, q: '' }} summaries={[]} />);

        expect(screen.getByText('Expiring by Thu 12 Nov.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Ask the assistant' }));
        expect(inertiaMock.router.post).toHaveBeenCalledWith('/assistant', { message: 'Suggest a dinner that uses Spinach.' });

        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));
        expect(inertiaMock.router.post).toHaveBeenLastCalledWith(
            '/briefing/feedback',
            { key: 'expiring_food:Spinach:2026-11-11', verdict: 'dismissed' },
            expect.anything(),
        );
    });

    test('the timeline, day by day', () => {
        render(
            <Briefing
                briefing={briefing}
                timeline={[
                    { kind: 'chore', title: 'Kitchen: Clean the oven', detail: '40 min', amount: null, who: 'Alex', date: '2026-11-10', time: '18:40', id: 'x1' },
                    { kind: 'expense', title: 'Market', detail: 'groceries', amount: 42.5, who: 'Maria', date: '2026-11-09', time: null, id: 'x2' },
                ]}
                kinds={['chore', 'expense']}
                filters={{ kind: null, q: '' }}
                summaries={[{ id: 's1', period: 'month', label: 'October 2026', text: 'October 2026: spent €1,512.40.' }]}
            />,
        );

        expect(screen.getByText('10 Nov')).toBeTruthy();
        expect(screen.getByText('Kitchen: Clean the oven · 40 min')).toBeTruthy();
        expect(screen.getByText('Market · groceries · €42.50')).toBeTruthy();
        expect(screen.getByText('October 2026: spent €1,512.40.')).toBeTruthy();
    });
});
