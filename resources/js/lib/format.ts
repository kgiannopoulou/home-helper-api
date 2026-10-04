/** €1,180: whole money, like wholeMoney() on the phone and Money::whole() on the server. */
export function money(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
        minimumFractionDigits: 0,
    }).format(Math.round(amount));
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** A calendar day, YYYY-MM-DD, read as that day wherever the browser is. */
function day(date: string): Date {
    const [y, m, d] = date.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
}

/** "28 Sep" (spelled out here: browsers disagree on "Sep" and "Sept") */
export function shortDate(date: string): string {
    const d = day(date);

    return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`;
}

/** "Mon 6 Oct" */
export function weekdayDate(date: string): string {
    return `${WEEKDAYS[day(date).getUTCDay()]} ${shortDate(date)}`;
}

/** 8123 → "8,123" */
export function number(n: number): string {
    return new Intl.NumberFormat('en-GB').format(Math.round(n));
}

/** "eating_out" → "Eating out" */
export function label(key: string): string {
    const words = key.replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}
