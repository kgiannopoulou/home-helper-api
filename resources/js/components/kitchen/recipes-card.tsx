import { router } from '@inertiajs/react';
import { BookOpen, ChefHat, Clock, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
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
import { ingredientLine } from '@/lib/kitchen';
import { cook, shopping, show, store } from '@/routes/kitchen/recipes';
import type { RecipeDetail, RecipeSuggestion } from '@/types/kitchen';

/**
 * What to cook (docs/kitchen.md#recipes): recipes ranked by what's in the
 * kitchen, food going off soon first. Cooking one takes its ingredients out
 * of the kitchen; what's missing can go on the shopping list.
 */
export function RecipesCard({ recipes }: { recipes: RecipeSuggestion[] }) {
    const [adding, setAdding] = useState(false);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ChefHat className="size-5" /> What to cook
                </CardTitle>
                <CardDescription>
                    From what's in the kitchen, using up what goes off first.
                    Salt, oil and the like are assumed.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {recipes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing in the kitchen matches a recipe yet.
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {recipes.map((r) => (
                            <RecipeItem key={r.id} recipe={r} />
                        ))}
                    </ul>
                )}
                {adding ? (
                    <RecipeForm onDone={() => setAdding(false)} />
                ) : (
                    <Button
                        variant="ghost"
                        className="self-start"
                        onClick={() => setAdding(true)}
                    >
                        Add your own recipe
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

export function RecipeItem({ recipe }: { recipe: RecipeSuggestion }) {
    const [detail, setDetail] = useState<RecipeDetail | null>(null);
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });
    const open = async () => {
        if (detail) {
            setDetail(null);

            return;
        }
        const response = await fetch(show.url(recipe.id), {
            headers: { Accept: 'application/json' },
        });
        if (response.ok) {
            setDetail((await response.json()).recipe);
        }
    };

    return (
        <li className="flex flex-col gap-2 rounded-lg border p-3">
            <div className="flex items-start justify-between gap-2">
                <div className="font-medium">{recipe.name}</div>
                {recipe.ready ? (
                    <Badge>Ready to cook</Badge>
                ) : recipe.missing.length > 0 ? (
                    <Badge variant="outline">
                        {recipe.missing.length} missing
                    </Badge>
                ) : (
                    <Badge variant="outline">Not quite enough</Badge>
                )}
            </div>
            <div className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                {recipe.minutes && (
                    <span className="flex items-center gap-1">
                        <Clock className="size-3" /> {recipe.minutes} min
                    </span>
                )}
                <span>serves {recipe.servings}</span>
                {recipe.own && <span>your recipe</span>}
            </div>
            {recipe.uses_soon.length > 0 && (
                <p className="text-sm text-amber-700 dark:text-amber-400">
                    Uses up {recipe.uses_soon.join(', ')}
                </p>
            )}
            {recipe.missing.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    Missing: {recipe.missing.join(', ')}
                </p>
            )}
            <div className="flex flex-wrap gap-1">
                <Button
                    size="sm"
                    variant={recipe.ready ? 'default' : 'outline'}
                    onClick={() => post(cook.url(recipe.id))}
                    aria-label={`Cooked ${recipe.name}`}
                >
                    <ChefHat /> Cooked it
                </Button>
                {!recipe.ready && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => post(shopping.url(recipe.id))}
                        aria-label={`Shop for ${recipe.name}`}
                    >
                        <ShoppingCart /> Add to list
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => void open()}
                    aria-expanded={detail !== null}
                    aria-label={`Recipe for ${recipe.name}`}
                >
                    <BookOpen /> Recipe
                </Button>
            </div>
            {detail && (
                <div className="text-sm">
                    <ul className="list-disc pl-5">
                        {detail.ingredients.map((i) => (
                            <li
                                key={i.ingredient}
                                className={
                                    i.optional || i.staple
                                        ? 'text-muted-foreground'
                                        : ''
                                }
                            >
                                {i.label}
                                {i.optional && ' (optional)'}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 whitespace-pre-line">{detail.steps}</p>
                </div>
            )}
        </li>
    );
}

export function RecipeForm({ onDone }: { onDone: () => void }) {
    const [name, setName] = useState('');
    const [servings, setServings] = useState('4');
    const [minutes, setMinutes] = useState('');
    const [ingredients, setIngredients] = useState('');
    const [steps, setSteps] = useState('');

    const submit = () =>
        router.post(
            store.url(),
            {
                name,
                servings: Number(servings),
                minutes: minutes ? Number(minutes) : null,
                steps,
                ingredients: ingredients
                    .split('\n')
                    .filter((l) => l.trim() !== '')
                    .map(ingredientLine),
            },
            { preserveScroll: true, onSuccess: onDone },
        );

    return (
        <div className="flex flex-col gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap gap-2">
                <Input
                    className="w-64"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Name"
                    aria-label="Recipe name"
                />
                <Input
                    className="w-24"
                    type="number"
                    min={1}
                    value={servings}
                    onChange={(e) => setServings(e.target.value)}
                    aria-label="Servings"
                />
                <Input
                    className="w-28"
                    type="number"
                    min={1}
                    value={minutes}
                    onChange={(e) => setMinutes(e.target.value)}
                    placeholder="minutes"
                    aria-label="Minutes"
                />
            </div>
            <textarea
                rows={4}
                className="rounded-md border bg-transparent px-3 py-2 text-sm"
                value={ingredients}
                onChange={(e) => setIngredients(e.target.value)}
                placeholder={
                    'One per line:\n500 g lentils\n1 onion\n2 tbsp tomato paste'
                }
                aria-label="Ingredients"
            />
            <textarea
                rows={3}
                className="rounded-md border bg-transparent px-3 py-2 text-sm"
                value={steps}
                onChange={(e) => setSteps(e.target.value)}
                placeholder="How to make it"
                aria-label="Steps"
            />
            <div className="flex gap-2">
                <Button
                    size="sm"
                    onClick={submit}
                    disabled={
                        !name.trim() || !ingredients.trim() || !steps.trim()
                    }
                >
                    Save recipe
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
