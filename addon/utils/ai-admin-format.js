const numberFormat = new Intl.NumberFormat();
const compactFormat = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
const relativeFormat = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto', style: 'narrow' });

const RELATIVE_UNITS = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
];

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
 * "3 min ago", "yesterday", or "now" for anything under a minute.
 */
export function formatRelative(value, now = Date.now()) {
    const time = value ? new Date(value).getTime() : NaN;
    if (Number.isNaN(time)) {
        return '';
    }

    const seconds = Math.round((time - now) / 1000);
    for (const [unit, size] of RELATIVE_UNITS) {
        if (Math.abs(seconds) >= size) {
            return relativeFormat.format(Math.round(seconds / size), unit);
        }
    }

    return 'now';
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
