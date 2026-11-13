import { router } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/smart-home/automations/templates';
import type { WorkflowTemplate } from '@/types/smart-home';

/** Ready-made workflows, filled in with the household's own devices (App\SmartHome\Automation\Templates) */
export function WorkflowTemplates({
    templates,
}: {
    templates: WorkflowTemplate[];
}) {
    return (
        <section className="space-y-2">
            <h3 className="text-sm font-medium">Ready-made workflows</h3>
            <ul className="grid gap-2 md:grid-cols-2">
                {templates.map((t) => {
                    const uses = Object.values(t.devices)
                        .flat()
                        .map((d) => d.name);

                    return (
                        <li
                            key={t.key}
                            className="flex flex-col gap-2 rounded-lg border p-3 text-sm"
                            data-testid={`template-${t.key}`}
                        >
                            <div className="font-medium">{t.name}</div>
                            <p className="text-muted-foreground">
                                {t.description}
                            </p>
                            {t.ready ? (
                                <p className="text-xs">
                                    Uses: {[...new Set(uses)].join(', ')}
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Needs a {t.missing.join(' and a ')}{' '}
                                    {t.missing.length ? 'imported first.' : ''}
                                </p>
                            )}
                            <div>
                                {t.added ? (
                                    <Badge variant="secondary">
                                        <Check /> Added
                                    </Badge>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!t.ready}
                                        aria-label={`Add ${t.name}`}
                                        onClick={() =>
                                            router.post(
                                                store.url(t.key),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Plus /> Add
                                    </Button>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
