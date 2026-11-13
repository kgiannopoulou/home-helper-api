import { router } from '@inertiajs/react';
import { Play, Trash2, Workflow } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ago } from '@/lib/computers';
import { destroy, run, update } from '@/routes/smart-home/automations';
import type { Automations, AutomationRunRow } from '@/types/smart-home';

const RUN_LABEL: Record<AutomationRunRow['status'], string> = {
    done: 'Done',
    partial: 'Partly',
    failed: 'Failed',
    skipped: 'Skipped',
    awaiting_approval: 'Waiting for approval',
};

/** The household's automations and what they did (docs/smart-home.md#automations) */
export function AutomationsCard({
    automations,
    canManage,
    children,
}: {
    automations: Automations;
    canManage: boolean;
    /** The "add a workflow" part (day 82) */
    children?: ReactNode;
}) {
    const opts = { preserveScroll: true };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Workflow className="size-5" /> Automations
                </CardTitle>
                <CardDescription>
                    When something happens, if the conditions hold, Home Helper
                    does it, with the same checks as a button press.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {automations.rules.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No automations yet.
                    </p>
                )}
                <ul className="space-y-2">
                    {automations.rules.map((r) => (
                        <li
                            key={r.id}
                            className="flex flex-wrap items-start gap-3 rounded-lg border p-3"
                            data-testid={`rule-${r.name}`}
                        >
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={r.enabled}
                                    disabled={!canManage}
                                    aria-label={`${r.enabled ? 'Switch off' : 'Switch on'} ${r.name}`}
                                    onChange={() =>
                                        router.patch(
                                            update.url(r.id),
                                            { enabled: !r.enabled },
                                            opts,
                                        )
                                    }
                                />
                            </label>
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">
                                    {r.name}
                                    {!r.enabled && (
                                        <Badge
                                            variant="outline"
                                            className="ml-2"
                                        >
                                            Off
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {r.description}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Last ran {ago(r.last_fired_at)}
                                </p>
                            </div>
                            {canManage && (
                                <div className="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={`Run ${r.name} now`}
                                        onClick={() =>
                                            router.post(run.url(r.id), {}, opts)
                                        }
                                    >
                                        <Play /> Run now
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Delete ${r.name}`}
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    `Delete "${r.name}"?`,
                                                )
                                            ) {
                                                router.delete(
                                                    destroy.url(r.id),
                                                    opts,
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                {children}

                {automations.runs.length > 0 && (
                    <details className="text-sm">
                        <summary className="cursor-pointer text-muted-foreground select-none">
                            What the automations did ({automations.runs.length})
                        </summary>
                        <ul className="mt-2 space-y-1">
                            {automations.runs.map((run) => (
                                <li key={run.id} className="flex gap-2">
                                    <Badge
                                        variant={
                                            run.status === 'done'
                                                ? 'secondary'
                                                : run.status === 'skipped'
                                                  ? 'outline'
                                                  : 'destructive'
                                        }
                                        className="shrink-0"
                                    >
                                        {RUN_LABEL[run.status]}
                                    </Badge>
                                    <span>
                                        <span className="font-medium">
                                            {run.rule}
                                        </span>
                                        : {run.trigger}
                                        {run.reason && ` — ${run.reason}`}
                                        {run.results
                                            .filter((x) => x.status !== 'done')
                                            .map((x) => ` — ${x.detail}`)}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {ago(run.at)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </CardContent>
        </Card>
    );
}
