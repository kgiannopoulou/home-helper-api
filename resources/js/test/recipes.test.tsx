import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { RecipeForm, RecipeItem } from '@/components/kitchen/recipes-card';
import { ingredientLine } from '@/lib/kitchen';
import { inertiaMock } from '@/test/inertia';
import type { RecipeSuggestion } from '@/types/kitchen';

vi.mock(
    '@inertiajs/react',
    async () => (await import('@/test/inertia')).inertiaMock,
);

const suggestion = (
    over: Partial<RecipeSuggestion> = {},
): RecipeSuggestion => ({
    id: 'r1',
    slug: 'strapatsada',
    name: 'Strapatsada',
    servings: 2,
    minutes: 15,
    tags: ['vegetarian'],
    own: false,
    required: 2,
    have: 2,
    short: 0,
    missing: [],
    uses_soon: ['feta'],
    soonest: '2026-11-14',
    ready: true,
    ...over,
});

describe('recipes', () => {
    beforeEach(() => vi.mocked(inertiaMock.router.post).mockClear());

    test.each([
        ['500 g lentils', { amount_text: '500 g', name: 'lentils' }],
        ['6 eggs', { amount_text: '6', name: 'eggs' }],
        [
            '2 tbsp tomato paste',
            { amount_text: '2 tbsp', name: 'tomato paste' },
        ],
        ['1,5 kg πατάτες', { amount_text: '1,5 kg', name: 'πατάτες' }],
        ['salt', { amount_text: '', name: 'salt' }],
    ])('reads "%s"', (line, parsed) =>
        expect(ingredientLine(line)).toEqual(parsed),
    );

    test('a ready recipe: cook it, see what it uses up', () => {
        render(<RecipeItem recipe={suggestion()} />);
        expect(screen.getByText('Ready to cook')).toBeInTheDocument();
        expect(screen.getByText('Uses up feta')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Shop for Strapatsada' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cooked Strapatsada' }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/kitchen/recipes/r1/cook',
            {},
            expect.anything(),
        );
    });

    test('a recipe with something missing can go on the list', () => {
        render(
            <RecipeItem
                recipe={suggestion({
                    ready: false,
                    missing: ['cucumber', 'onion'],
                    uses_soon: [],
                })}
            />,
        );
        expect(screen.getByText('2 missing')).toBeInTheDocument();
        expect(
            screen.getByText('Missing: cucumber, onion'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Shop for Strapatsada' }),
        );
        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/kitchen/recipes/r1/shopping',
            {},
            expect.anything(),
        );
    });

    test('shows the recipe on demand', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    recipe: {
                        id: 'r1',
                        name: 'Strapatsada',
                        servings: 2,
                        minutes: 15,
                        tags: [],
                        own: false,
                        steps: 'Grate the tomatoes.',
                        ingredients: [
                            {
                                ingredient: 'egg',
                                amount: 4,
                                unit: 'pcs',
                                label: '4 egg',
                                optional: false,
                                staple: false,
                            },
                        ],
                    },
                }),
            ),
        );
        render(<RecipeItem recipe={suggestion()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Recipe for Strapatsada' }),
        );
        expect(
            await screen.findByText('Grate the tomatoes.'),
        ).toBeInTheDocument();
        expect(screen.getByText('4 egg')).toBeInTheDocument();
        fetchSpy.mockRestore();
    });

    test('adds your own recipe, one ingredient per line', () => {
        render(<RecipeForm onDone={() => {}} />);
        fireEvent.change(screen.getByLabelText('Recipe name'), {
            target: { value: 'Fakes' },
        });
        fireEvent.change(screen.getByLabelText('Ingredients'), {
            target: { value: '500 g lentils\n1 onion\n\nsalt' },
        });
        fireEvent.change(screen.getByLabelText('Steps'), {
            target: { value: 'Simmer.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save recipe' }));

        expect(inertiaMock.router.post).toHaveBeenCalledWith(
            '/kitchen/recipes',
            {
                name: 'Fakes',
                servings: 4,
                minutes: null,
                steps: 'Simmer.',
                ingredients: [
                    { amount_text: '500 g', name: 'lentils' },
                    { amount_text: '1', name: 'onion' },
                    { amount_text: '', name: 'salt' },
                ],
            },
            expect.anything(),
        );
    });
});
