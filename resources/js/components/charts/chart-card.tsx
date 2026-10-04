import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type TableColumn<T> = {
    label: string;
    value: (row: T) => ReactNode;
    numeric?: boolean;
};

/**
 * A chart in a card, with the same numbers as a table underneath ("Show as
 * table"). The table is there for screen readers, for reading exact values, and
 * because some chart colours are light against a white card.
 */
export function ChartCard<T>({
    title,
    description,
    children,
    rows,
    columns,
    rowKey,
}: {
    title: string;
    description?: ReactNode;
    children: ReactNode;
    rows: T[];
    columns: TableColumn<T>[];
    rowKey: (row: T) => string;
}) {
    return (
        <Card className="gap-4">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="space-y-3">
                <div aria-hidden="true">{children}</div>
                <details className="text-sm">
                    <summary className="cursor-pointer text-muted-foreground select-none">
                        Show as table
                    </summary>
                    <div className="mt-2 overflow-x-auto">
                        <table className="w-full text-left">
                            <caption className="sr-only">{title}</caption>
                            <thead className="text-muted-foreground">
                                <tr>
                                    {columns.map((c) => (
                                        <th
                                            key={c.label}
                                            scope="col"
                                            className={`py-1 pr-4 font-medium ${c.numeric ? 'text-right' : ''}`}
                                        >
                                            {c.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={rowKey(row)}
                                        className="border-t border-border"
                                    >
                                        {columns.map((c) => (
                                            <td
                                                key={c.label}
                                                className={`py-1 pr-4 ${c.numeric ? 'text-right tabular-nums' : ''}`}
                                            >
                                                {c.value(row)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}

/** Shared axis and grid styling: recessive, so the data carries the chart. */
export const axis = {
    stroke: 'var(--viz-axis)',
    tick: { fill: 'var(--viz-axis)', fontSize: 12 },
    tickLine: false,
    axisLine: { stroke: 'var(--viz-grid)' },
} as const;

export const grid = {
    stroke: 'var(--viz-grid)',
    vertical: false,
} as const;

/** Tooltip box in the card's own colours. */
export const tooltipStyle = {
    contentStyle: {
        background: 'var(--popover)',
        border: '1px solid var(--border)',
        borderRadius: 8,
        color: 'var(--popover-foreground)',
        fontSize: 12,
    },
    labelStyle: { color: 'var(--popover-foreground)', fontWeight: 600 },
    itemStyle: { color: 'var(--popover-foreground)' },
    cursor: { fill: 'var(--muted)', opacity: 0.5 },
} as const;
