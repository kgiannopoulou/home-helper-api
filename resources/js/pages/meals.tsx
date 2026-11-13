import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    ShoppingCart,
    Sparkles,
    X,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
import { shortDate, weekdayDate } from '@/lib/format';
import { addDays, lineAmount } from '@/lib/kitchen';
import { meals as mealsRoute } from '@/routes';
import { clear, cooked, plan, set } from '@/routes/meals';
import type {
    Meal,
    MealDay,
    MealEntry,
    PlanNeed,
    RecipeChoice,
} from '@/types/kitchen';

type Props = {
    from: string;
    today: string;
    days: MealDay[];
    recipes: RecipeChoice[];
    shopping: PlanNeed[];
    /** The household's size: the default number of people */
    servings: number;
};

const MEALS: Meal[] = ['breakfast', 'lunch', 'dinner'];
const MEAL_LABEL: Record<Meal, string> = {
    breakfast: 'Breakfast',
    lunch: 'Lunch',
    dinner: 'Dinner',
};

/**
 * The meal plan (docs/kitchen.md#meal-plan): a week of meals, "plan the
 * week" from what's in the kitchen, and what the plan still needs, which
 * Home Helper keeps on the shopping list.
 */
export default function Meals({
    from,
    today,
    days,
    recipes,
    shopping,
    servings,
}: Props) {
    const week = (offset: number) =>
        mealsRoute({ query: { from: addDays(from, offset) } });

    return (
        <>
            <Head title="Meals" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Meals"
                        description="The week's meals, planned around what's in the kitchen."
                    />
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="icon" asChild>
                            <Link href={week(-7)} aria-label="Previous week">
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <span className="text-sm">
                            {shortDate(from)} – {shortDate(addDays(from, 6))}
                        </span>
                        <Button variant="outline" size="icon" asChild>
                            <Link href={week(7)} aria-label="Next week">
                                <ChevronRight />
                            </Link>
                        </Button>
                        <Button
                            onClick={() =>
                                router.post(
                                    plan.url(),
                                    {
                                        from: from < today ? today : from,
                                        days: 7,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Sparkles /> Plan the dinners
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {days.map((d) => (
                        <Card
                            key={d.date}
                            className={d.date === today ? 'border-primary' : ''}
                        >
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    {weekdayDate(d.date)}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {MEALS.map((m) => (
                                    <MealSlot
                                        key={m}
                                        date={d.date}
                                        meal={m}
                                        entry={d.meals[m]}
                                        recipes={recipes}
                                        servings={servings}
                                        past={d.date < today}
                                    />
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ShoppingCart className="size-5" /> Still needed
                            this week
                        </CardTitle>
                        <CardDescription>
                            What the planned meals need beyond the kitchen. It's
                            kept on the shopping list as the plan changes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {shopping.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing: the kitchen has it all.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {shopping.map((n) => (
                                    <li
                                        key={n.ingredient}
                                        className="flex justify-between gap-2 py-1.5"
                                    >
                                        <span>
                                            <span className="font-medium capitalize">
                                                {n.ingredient}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                for {n.for.join(', ')}
                                            </span>
                                        </span>
                                        <span className="tabular-nums">
                                            {lineAmount(n)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

export function MealSlot({
    date,
    meal,
    entry,
    recipes,
    servings,
    past,
}: {
    date: string;
    meal: Meal;
    entry: MealEntry | null;
    recipes: RecipeChoice[];
    servings: number;
    past: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [choice, setChoice] = useState('');
    const [people, setPeople] = useState(String(entry?.servings ?? servings));
    const save = () => {
        const recipe = recipes.find((r) => r.id === choice);
        router.put(
            set.url({ date, meal }),
            {
                recipe_id: recipe ? recipe.id : null,
                title: recipe ? null : choice,
                servings: Number(people),
            },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    };
    const fits = recipes.filter((r) =>
        meal === 'breakfast'
            ? r.tags.includes('breakfast')
            : !r.tags.includes('breakfast'),
    );

    if (editing) {
        return (
            <div className="flex flex-col gap-1 rounded-md border p-2">
                <div className="text-xs text-muted-foreground">
                    {MEAL_LABEL[meal]}
                </div>
                <Input
                    list={`recipes-${meal}`}
                    value={recipes.find((r) => r.id === choice)?.name ?? choice}
                    onChange={(e) => {
                        const match = recipes.find(
                            (r) => r.name === e.target.value,
                        );
                        setChoice(match ? match.id : e.target.value);
                    }}
                    placeholder="A recipe, or e.g. Eating out"
                    aria-label={`${MEAL_LABEL[meal]} on ${date}`}
                />
                <datalist id={`recipes-${meal}`}>
                    {fits.map((r) => (
                        <option key={r.id} value={r.name}>
                            {r.suggested ? '✓ ingredients at home' : ''}
                        </option>
                    ))}
                </datalist>
                <div className="flex items-center gap-1">
                    <Input
                        type="number"
                        min={1}
                        className="h-8 w-16"
                        value={people}
                        onChange={(e) => setPeople(e.target.value)}
                        aria-label="People"
                    />
                    <span className="text-xs text-muted-foreground">
                        people
                    </span>
                    <Button
                        size="sm"
                        className="ml-auto"
                        onClick={save}
                        disabled={choice.trim() === ''}
                    >
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(false)}
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        );
    }

    if (!entry) {
        return (
            <button
                type="button"
                className="rounded-md border border-dashed px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-muted"
                onClick={() => setEditing(true)}
                disabled={past}
            >
                {MEAL_LABEL[meal]}: add
            </button>
        );
    }

    return (
        <div className="flex flex-col gap-1 rounded-md border px-2 py-1.5">
            <div className="flex items-start justify-between gap-1">
                <div className="min-w-0">
                    <div className="text-xs text-muted-foreground">
                        {MEAL_LABEL[meal]} · {entry.servings}{' '}
                        {entry.servings === 1 ? 'person' : 'people'}
                        {entry.minutes ? ` · ${entry.minutes} min` : ''}
                    </div>
                    <button
                        type="button"
                        className="text-left text-sm font-medium hover:underline"
                        onClick={() => setEditing(true)}
                    >
                        {entry.label}
                    </button>
                </div>
                {entry.cooked ? (
                    <Badge variant="outline">cooked</Badge>
                ) : (
                    entry.auto && <Badge variant="outline">suggested</Badge>
                )}
            </div>
            {!entry.cooked && (
                <div className="flex gap-1">
                    {entry.recipe_id && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7"
                            onClick={() =>
                                router.post(
                                    cooked.url(entry.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            aria-label={`Cooked ${entry.label}`}
                        >
                            <Check /> Cooked
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-7"
                        onClick={() =>
                            router.delete(clear.url({ date, meal }), {
                                preserveScroll: true,
                            })
                        }
                        aria-label={`Remove ${entry.label}`}
                    >
                        <X />
                    </Button>
                </div>
            )}
        </div>
    );
}

Meals.layout = {
    breadcrumbs: [{ title: 'Meals', href: mealsRoute() }],
};
