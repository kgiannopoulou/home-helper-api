/** App\Ops\SystemHealth::run(), as /system/health receives it */
export type CheckStatus = 'ok' | 'warn' | 'fail';

export type HealthCheck = {
    status: CheckStatus;
    summary: string;
    ms: number;
    details: Record<string, unknown>;
};

export type HealthReport = {
    status: 'ok' | 'degraded' | 'down';
    checked_at: string;
    checks: Record<string, HealthCheck>;
};

export type AppFacts = {
    environment: string;
    debug: boolean;
    php: string;
    laravel: string;
    version: string | null;
    timezone: string;
    queue: string;
    cache: string;
    config_cached: boolean;
};

export type OpsAlert = {
    key: string;
    title: string;
    at: string;
    context: Record<string, unknown>;
};

export type FailedJob = {
    uuid: string;
    queue: string;
    job: string;
    error: string;
    failed_at: string;
};

export type HouseholdJobState = {
    every_hours: number;
    last_status: 'running' | 'succeeded' | 'failed' | null;
    last_started_at: string | null;
    failed_24h: number;
    late: boolean;
};
