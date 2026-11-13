import { Droplets, Thermometer } from 'lucide-react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    axis,
    ChartCard,
    grid,
    tooltipStyle,
} from '@/components/charts/chart-card';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Climate, RoomComfort } from '@/types/smart-home';

export const COMFORT_LABEL: Record<RoomComfort['status'], string> = {
    comfortable: 'Comfortable',
    cold: 'Cold',
    warm: 'Warm',
    humid: 'Humid',
    very_humid: 'Very humid',
    dry: 'Dry',
};

const SERIES = [1, 2, 3, 4, 5, 6].map((n) => `var(--series-${n})`);

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });

export function ComfortCard({ rooms }: { rooms: RoomComfort[] }) {
    if (rooms.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>How the rooms feel</CardTitle>
                <CardDescription>
                    18–24 °C and 40–60 % humidity are comfortable; above 65 %,
                    mould likes it.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {rooms.map((r) => (
                    <div
                        key={r.room}
                        className="space-y-1 rounded-lg border p-3"
                        data-testid={`comfort-${r.room}`}
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-medium">{r.room}</span>
                            <Badge
                                variant={
                                    r.status === 'comfortable'
                                        ? 'secondary'
                                        : 'destructive'
                                }
                            >
                                {COMFORT_LABEL[r.status]}
                            </Badge>
                        </div>
                        <div className="flex gap-4 text-sm tabular-nums">
                            {r.temperature !== null && (
                                <span>
                                    <Thermometer className="mr-1 inline size-3.5" />
                                    {r.temperature} °C
                                </span>
                            )}
                            {r.humidity !== null && (
                                <span>
                                    <Droplets className="mr-1 inline size-3.5" />
                                    {r.humidity} %
                                </span>
                            )}
                        </div>
                        {r.dew_point !== null && (
                            <p className="text-xs text-muted-foreground">
                                Dew point {r.dew_point} °C: surfaces colder than
                                that get damp
                            </p>
                        )}
                        {r.advice && <p className="text-sm">{r.advice}</p>}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export function ClimateChart({
    climate,
    kind,
}: {
    climate: Climate;
    kind: 'temperature' | 'humidity';
}) {
    const sensors = climate.sensors.filter((s) => s.kind === kind);
    if (sensors.length === 0 || climate.history.length < 2) {
        return null;
    }
    const unit = kind === 'temperature' ? '°C' : '%';
    const label = (s: Climate['sensors'][number]) => s.room ?? s.name;

    return (
        <ChartCard
            title={kind === 'temperature' ? 'Temperature' : 'Humidity'}
            description="The last 24 hours, averaged every 15 minutes"
            rows={climate.history}
            rowKey={(p) => String(p.at)}
            columns={[
                { label: 'Time', value: (p) => time(String(p.at)) },
                ...sensors.map((s) => ({
                    label: `${label(s)} ${unit}`,
                    value: (p: Climate['history'][number]) =>
                        (p[s.id] as number | undefined) ?? '–',
                    numeric: true,
                })),
            ]}
        >
            <div className="h-52">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={climate.history}
                        margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                    >
                        <CartesianGrid {...grid} />
                        <XAxis dataKey="at" tickFormatter={time} {...axis} />
                        <YAxis
                            width={36}
                            domain={
                                kind === 'humidity'
                                    ? [0, 100]
                                    : ['dataMin - 1', 'dataMax + 1']
                            }
                            {...axis}
                            axisLine={false}
                        />
                        <Tooltip
                            {...tooltipStyle}
                            labelFormatter={(v) => time(String(v))}
                        />
                        <Legend />
                        {sensors.map((s, i) => (
                            <Line
                                key={s.id}
                                dataKey={s.id}
                                name={label(s)}
                                stroke={SERIES[i % SERIES.length]}
                                strokeWidth={2}
                                dot={climate.history.length < 30}
                                connectNulls
                                isAnimationActive={false}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </ChartCard>
    );
}
