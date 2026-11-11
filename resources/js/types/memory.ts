/** App\Memory\MemoryPresenter and App\Briefing, as the Memory and Today pages receive them */

export type Fact = {
    id: string;
    kind: 'fact' | 'preference' | 'routine';
    content: string;
    about: { id: number; name: string } | null;
    added_by: string | null;
    source: 'person' | 'assistant';
    private: boolean;
    mine: boolean;
    created_at: string;
};

export type NoteSummary = {
    id: string;
    kind: 'note' | 'document';
    title: string;
    excerpt: string;
    file: { name: string; mime: string | null; size: number | null } | null;
    added_by: string | null;
    private: boolean;
    mine: boolean;
    created_at: string;
    updated_at: string;
};

export type SearchHit = {
    source_type: 'note' | 'document' | 'fact' | 'conversation' | 'summary';
    source_id: string;
    title: string;
    text: string;
    occurred_on: string | null;
    score: number;
    matched_by: ('meaning' | 'keywords')[];
};

export type Recommendation = {
    key: string;
    rule: string;
    title: string;
    reason: string;
    ask: string | null;
    priority: number;
};

type Event = { title: string; starts: string; location?: string | null };
type Chore = {
    id: string;
    name: string;
    room: string;
    days_overdue?: number;
    yours: boolean;
};
type Bill = {
    name?: string;
    title?: string;
    amount: number | null;
    due_on: string;
};

export type Briefing = {
    date: string;
    weekday: string;
    for: string;
    currency: string;
    headline: string;
    weather: {
        minimum_temperature_c: number;
        maximum_temperature_c: number;
        precipitation_mm: number;
        max_wind_gust_kmh: number;
    } | null;
    weather_note: string | null;
    calendar: { today: Event[]; tomorrow: Event[] };
    tasks: {
        todos: { id: string; title: string; overdue: boolean }[];
        chores_due: Chore[];
        chores_overdue: Chore[];
    };
    shopping: {
        count: number;
        estimated_total: number | null;
        usual_day: { day: string; next_on: string } | null;
        today_is_shopping_day: boolean;
        running_out: { name: string; days_left: number }[];
    } | null;
    kitchen: {
        expiring: { name: string; expires_on: string | null }[];
    } | null;
    money: {
        spent_yesterday: number;
        month_to_date: number;
        budget: number | null;
        forecast: number | null;
        over_budget: number | null;
    } | null;
    bills: Bill[];
    plants: {
        to_water: {
            name: string;
            amount_ml: number | null;
            skip_for_rain: boolean;
        }[];
        other_care: { name: string; what: string }[];
        weather_warnings: { name: string; warning: string }[];
    } | null;
    maintenance: {
        due: { title: string; kind: string; due_on: string; overdue: boolean }[];
        supplies_low_or_out: { name: string; level: string }[];
    } | null;
    recommendations: Recommendation[];
    unavailable: string[];
};

export type TimelineEntry = {
    kind: string;
    title: string;
    detail: string | null;
    amount: number | null;
    who: string | null;
    date: string;
    time: string | null;
    id: string;
};

export type HouseholdSummary = {
    id: string;
    period: 'week' | 'month';
    label: string;
    text: string;
};
