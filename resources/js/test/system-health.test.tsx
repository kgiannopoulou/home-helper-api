import { render, screen, within } from '@testing-library/react';
import { describe, expect, test, vi } from 'vitest';
import SystemHealth from '@/pages/system-health';
import type { HealthCheck, HealthReport } from '@/types/health';

vi.mock('@inertiajs/react', async () => ({
    ...(await import('@/test/inertia')).inertiaMock,
    usePoll: vi.fn(),
}));

const check = (over: Partial<HealthCheck> = {}): HealthCheck => ({
    status: 'ok',
    summary: 'fine',
    ms: 1.2,
    details: {},
    ...over,
});

const report = (over: Partial<HealthReport> = {}): HealthReport => ({
    status: 'ok',
    checked_at: '2026-11-10T10:00:00+02:00',
    checks: {
        database: check({ summary: 'mysql 8.4.6, database home_helper_api' }),
        redis: check(),
        scheduler: check({ status: 'fail', summary: 'never ran' }),
        household_jobs: check({
            status: 'warn',
            details: {
                jobs: {
                    BudgetAlert: {
                        every_hours: 24,
                        last_status: 'failed',
                        last_started_at: '2026-11-09T18:00:00+02:00',
                        failed_24h: 2,
                        late: false,
                    },
                    WeeklySummary: {
                        every_hours: 168,
                        last_status: null,
                        last_started_at: null,
                        failed_24h: 0,
                        late: true,
                    },
                },
            },
        }),
    },
    ...over,
});

const app = {
    environment: 'production',
    debug: false,
    php: '8.4.25',
    laravel: '13.17.0',
    version: 'abc1234',
    timezone: 'Europe/Athens',
    queue: 'redis',
    cache: 'redis',
    config_cached: true,
};

describe('System health page', () => {
    test('says how the system is doing overall, in words', () => {
        render(
            <SystemHealth
                health={report({ status: 'degraded' })}
                app={app}
                alerts={[]}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'Working, with problems',
        );
    });

    test('each check shows its status as text, not only a colour', () => {
        render(<SystemHealth health={report()} app={app} alerts={[]} />);

        const db = screen.getByTestId('check-database');
        expect(within(db).getByText('Database')).toBeInTheDocument();
        expect(within(db).getByText('OK')).toBeInTheDocument();
        expect(
            within(db).getByText('mysql 8.4.6, database home_helper_api'),
        ).toBeInTheDocument();

        expect(
            within(screen.getByTestId('check-scheduler')).getByText('Failing'),
        ).toBeInTheDocument();
    });

    test('household jobs: failures counted, a job that never ran is late', () => {
        render(<SystemHealth health={report()} app={app} alerts={[]} />);

        const row = screen.getByText('WeeklySummary').closest('tr')!;
        expect(within(row).getByText('late')).toBeInTheDocument();
        expect(within(row).getByText('never')).toBeInTheDocument();

        const budget = screen.getByText('BudgetAlert').closest('tr')!;
        expect(within(budget).getByText('2')).toBeInTheDocument();
    });

    test('lists recent alerts, or says there are none', () => {
        const { rerender } = render(
            <SystemHealth health={report()} app={app} alerts={[]} />,
        );
        expect(screen.getByText('No alerts.')).toBeInTheDocument();

        rerender(
            <SystemHealth
                health={report()}
                app={app}
                alerts={[
                    {
                        key: 'backup-failed',
                        title: 'Database backup failed',
                        at: '2026-11-10T03:00:05+02:00',
                        context: {},
                    },
                ]}
            />,
        );
        expect(screen.getByText('Database backup failed')).toBeInTheDocument();
    });
});
