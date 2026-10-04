// Props the dashboard pages get from the Laravel controllers (app/Http/Controllers/Web).
// They mirror the get() shapes of the SQL query classes in app/Queries.

export type CurrentHousehold = {
    id: string;
    name: string;
    currency: string;
    role: 'owner' | 'member';
};

export type HouseholdOption = { id: string; name: string };

export type Forecast = {
    forecast: number | null;
    budget: number | null;
    over_budget: number | null;
    spent: number;
    bills: number;
    projected_from_pace: number;
    day: number;
    days_in_month: number;
    history: {
        months: number;
        usual_by_this_day: number | null;
        average_month: number | null;
    };
};

export type WeeklySpendingRow = {
    week_start: string;
    category: string;
    total: number;
    expenses: number;
    previous_total: number | null;
    change_pct: number | null;
};

export type HealthWeek = {
    week_start: string;
    sleep_hours: number | null;
    sleep_quality: number | null;
    nights: number;
    steps: number;
    steps_per_day: number | null;
    workouts: number;
    workout_minutes: number;
};

export type OverdueChore = {
    id: string;
    name: string;
    room: string;
    every_days: number;
    last_done: string;
    days_overdue: number;
};

export type SlippingChore = {
    id: string;
    name: string;
    room: string;
    every_days: number;
    trend: 'late' | 'early';
    typical_days: number;
    gaps: number[];
    suggested_every_days: number;
};

export type FairShare = {
    user_id: number;
    name: string;
    minutes: number;
    chores: number;
    share: number;
};

export type PlanItem = {
    key: string;
    kind: 'chore' | 'meal' | 'errand';
    title: string;
    detail: string | null;
    minutes: number;
    wanted: string | null;
    date: string;
    start: string;
    end: string;
    moved: boolean;
    /** Already added to the calendar by an earlier Apply */
    applied: boolean;
};

export type PlanDay = {
    date: string;
    free: { from: string; to: string }[];
    busy: { title: string; from: string; to: string; all_day: boolean }[];
};

export type WeekPlan = {
    week_start: string;
    days: PlanDay[];
    items: PlanItem[];
    unplaced: Omit<PlanItem, 'date' | 'start' | 'end' | 'moved' | 'applied'>[];
};

export type Member = {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'member';
    joined: string | null;
};

export type PendingInvite = {
    id: string;
    email: string;
    role: 'owner' | 'member';
    expires_at: string;
};
