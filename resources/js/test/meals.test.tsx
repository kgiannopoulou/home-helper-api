import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { addDays } from '@/lib/kitchen';
import Meals, { MealSlot } from '@/pages/meals';
import { inertiaMock } from '@/test/inertia';
import type { MealDay, MealEntry, RecipeChoice } from '@/types/kitchen';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const recipes: RecipeChoice[] = [
    {
        id: 'fakes',
        name: 'Lentil soup (fakes)',
        minutes: 50,
        tags: ['vegan'],
        suggested: true,
    },
    {
        id: 'oats',
        name: 'Overnight oats',
        minutes: 5,
        tags: ['breakfast'],
        suggested: false,
    },
];

const entry = (over: Partial<MealEntry> = {}): MealEntry => ({
    id: 'e1',
    date: '2026-11-16',
    meal: 'dinner',
    recipe_id: 'fakes',
    label: 'Lentil soup (fakes)',
    servings: 2,
    auto: true,
    cooked: false,
    minutes: 50,
    ...over,
});

const days: MealDay[] = Array.from({ length: 7 }, (_, i) => ({
    date: addDays('2026-11-16', i),
    meals: { breakfast: null, lunch: null, dinner: i === 0 ? entry() : null },
}));

describe('meals', () => {
    beforeEach(() => {
        vi.mocked(inertiaMock.router.post).mockClear();
        vi.mocked(inertiaMock.router.put).mockClear();
    });

    test('adds calendar days across months', () => {
        expect(addDays('2026-11-28', 7)).toBe('2026-12-05');
        expect(addDays('2026-11-16', -7)).toBe('2026-11-09');
    });

    test('the week: meals, what is still needed, plan the dinners', () => {
        render(
            <Meals
                from="2026-11-16"
                today="2026-11-13"
                days={days}
                recipes={recipes}
                shopping={[
                    {
                        ingredient: 'lentils',
                        amount: 500,
                        unit: 'g',
                        for: ['Lentil soup (fakes)'],
                    },
                ]}
                servings={2}
            />,
        );
        expect(screen.getByText('Mon 16 Nov')).toBeInTheDocument();
        expect(
            screen.getAllByText('Lentil soup (fakes)').length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('suggested')).toBeInTheDocument();
        expect(screen.getByText('500 g')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Plan the dinners/ }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/meals/plan',
            { from: '2026-11-16', days: 7 },
            expect.anything(),
        );
    });

    test('a meal: cooked or removed', () => {
        render(
            <MealSlot
                date="2026-11-16"
                meal="dinner"
                entry={entry()}
                recipes={recipes}
                servings={2}
                past={false}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cooked Lentil soup (fakes)' }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/meals/e1/cooked',
            {},
            expect.anything(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Lentil soup (fakes)' }),
        );
        expect(inertiaMock.router.delete).toHaveBeenCalledWith(
            '/meals/2026-11-16/dinner',
            expect.anything(),
        );
    });

    test('plans a recipe or just a title', () => {
        render(
            <MealSlot
                date="2026-11-17"
                meal="dinner"
                entry={null}
                recipes={recipes}
                servings={2}
                past={false}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Dinner: add' }));
        fireEvent.change(screen.getByLabelText('Dinner on 2026-11-17'), {
            target: { value: 'Lentil soup (fakes)' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(inertiaMock.router.put).toHaveBeenLastCalledWith(
            '/meals/2026-11-17/dinner',
            { recipe_id: 'fakes', title: null, servings: 2 },
            expect.anything(),
        );

        fireEvent.change(screen.getByLabelText('Dinner on 2026-11-17'), {
            target: { value: 'Eating out' },
        });
        fireEvent.change(screen.getByLabelText('People'), {
            target: { value: '3' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(inertiaMock.router.put).toHaveBeenLastCalledWith(
            '/meals/2026-11-17/dinner',
            { recipe_id: null, title: 'Eating out', servings: 3 },
            expect.anything(),
        );
    });

    test("a past day can't get new meals", () => {
        render(
            <MealSlot
                date="2026-11-10"
                meal="lunch"
                entry={null}
                recipes={recipes}
                servings={2}
                past
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Lunch: add' }),
        ).toBeDisabled();
    });
});
