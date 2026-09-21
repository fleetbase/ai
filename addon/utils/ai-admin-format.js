import { format, formatDistanceStrict, isValid, parseISO } from 'date-fns';

// The browser's language, passed explicitly: consoles that force-load the formatjs Intl polyfills
// otherwise default to whichever locale's data registered first, which formats everything in Arabic.
export const LOCALE = (typeof navigator !== 'undefined' && navigator.language) || 'en-US';

const numberFormat = new Intl.NumberFormat(LOCALE);
const compactFormat = new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 });

function toDate(value) {
    if (value instanceof Date) {
        return value;
    }

    const date = typeof value === 'string' ? parseISO(value) : new Date(value);

    return value !== null && value !== undefined && value !== '' && isValid(date) ? date : null;
}

function toNumber(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number : 0;
}

/**
 * 12400 → "12,400"
 */
export function formatNumber(value) {
    return numberFormat.format(toNumber(value));
}

/**
 * 12400 → "12.4K"; small numbers are left as they are.
 */
export function formatCompact(value) {
    const number = toNumber(value);

    return Math.abs(number) < 1000 ? numberFormat.format(number) : compactFormat.format(number);
}

/**
 * Share of a total as a percentage with at most one decimal, or null when there is no total.
 */
export function formatPercent(part, total) {
    const whole = toNumber(total);
    if (whole <= 0) {
        return null;
    }

    return `${Math.round((toNumber(part) / whole) * 1000) / 10}%`;
}

/**
 * "3 hours ago", or "just now" for anything under a minute.
 */
export function formatRelative(value, now = Date.now()) {
    const date = toDate(value);
    if (!date) {
        return '';
    }

    return Math.abs(now - date.getTime()) < 60000 ? 'just now' : formatDistanceStrict(date, now, { addSuffix: true });
}

/**
 * A calendar day such as "18 Sep", for chart labels.
 */
export function formatDay(value) {
    const date = toDate(typeof value === 'string' ? value.slice(0, 10) : value);

    return date ? format(date, 'd MMM') : '';
}

/**
 * Time between two timestamps: "850 ms", "3.1 s", or "2 min 5 s".
 */
export function formatDuration(start, end) {
    const from = start ? new Date(start).getTime() : NaN;
    const to = end ? new Date(end).getTime() : NaN;
    if (Number.isNaN(from) || Number.isNaN(to) || to < from) {
        return null;
    }

    const milliseconds = to - from;
    if (milliseconds < 1000) {
        return `${milliseconds} ms`;
    }

    if (milliseconds < 60000) {
        return `${Math.round(milliseconds / 100) / 10} s`;
    }

    const minutes = Math.floor(milliseconds / 60000);
    const seconds = Math.round((milliseconds % 60000) / 1000);

    return seconds ? `${minutes} min ${seconds} s` : `${minutes} min`;
}

/**
 * A readable name for an audit step: tool calls show the tool, everything else its type.
 */
export function stepLabel(step = {}) {
    if (step.type === 'tool_call') {
        return `Tool: ${step.input?.name ?? step.tool ?? 'unknown'}`;
    }

    return String(step.type ?? 'step').replace(/_/g, ' ');
}

export function formatJson(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

/**
 * The Badge status used for an answer's status.
 */
export function statusType(status) {
    if (['answered', 'applied', 'completed', 'active'].includes(status)) {
        return 'success';
    }

    // ember-ui has no "danger" badge; "failed" is its red variant.
    if (['failed', 'apply_failed'].includes(status)) {
        return 'failed';
    }

    if (['running', 'pending'].includes(status)) {
        return 'info';
    }

    return 'default';
}
