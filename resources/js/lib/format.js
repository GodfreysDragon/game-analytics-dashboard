const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const whole = new Intl.NumberFormat('en');
const usd = new Intl.NumberFormat('en', { style: 'currency', currency: 'USD' });
const usdCompact = new Intl.NumberFormat('en', { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 });

export const isMissing = (value) => value === null || value === undefined || Number.isNaN(value);

export function number(value, { exact = false } = {}) {
    if (isMissing(value)) return '–';
    return !exact && Math.abs(value) >= 10_000 ? compact.format(value) : whole.format(Math.round(value * 10) / 10);
}

export function money(value, { cents = true } = {}) {
    if (isMissing(value)) return '–';
    if (Math.abs(value) >= 100_000) return usdCompact.format(value);
    return cents ? usd.format(value) : usd.format(Math.round(value)).replace(/\.00$/, '');
}

export function percent(value, digits = 1) {
    if (isMissing(value)) return '–';
    return `${(value * 100).toFixed(digits).replace(/\.0+$/, '')}%`;
}

export function signedPercent(value) {
    if (isMissing(value)) return null;
    const sign = value > 0 ? '+' : value < 0 ? '−' : '±';
    return `${sign}${Math.abs(value * 100).toFixed(1)}%`;
}

export function duration(seconds) {
    if (isMissing(seconds)) return '–';
    if (seconds < 60) return `${Math.round(seconds)}s`;
    if (seconds < 3600) {
        const m = Math.floor(seconds / 60);
        const s = Math.round(seconds % 60);
        return s ? `${m}m ${s}s` : `${m}m`;
    }
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return m ? `${h}h ${m}m` : `${h}h`;
}

export function minutesLabel(minutes) {
    if (minutes % 1440 === 0) return `${minutes / 1440} day${minutes === 1440 ? '' : 's'}`;
    if (minutes % 60 === 0) return `${minutes / 60} hour${minutes === 60 ? '' : 's'}`;
    return `${minutes} min`;
}

/** "2026-06-14" -> "Jun 14" (dates are UTC calendar days). */
export function shortDate(date) {
    const d = new Date(`${date}T00:00:00Z`);
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

export function longDate(date) {
    const d = new Date(`${date}T00:00:00Z`);
    return d.toLocaleDateString('en', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}

export function dateTime(iso) {
    if (!iso) return '–';
    return new Date(iso).toLocaleString('en', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
}

export function timeAgo(iso) {
    if (!iso) return 'never';
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

export function isoDay(date) {
    return date.toISOString().slice(0, 10);
}

/** Readable label for a snake_case event name. */
export function eventLabel(name) {
    return name.replace(/_/g, ' ');
}
