import { tracked } from '@glimmer/tracking';

export const SESSION_STATUS_OPTIONS = [
    { label: 'Any session', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Ended', value: 'ended' },
];

export const TASK_STATUS_OPTIONS = [
    { label: 'Any answer status', value: '' },
    { label: 'Answered', value: 'answered' },
    { label: 'Applied', value: 'applied' },
    { label: 'Failed', value: 'failed' },
    { label: 'Apply failed', value: 'apply_failed' },
    { label: 'Cancelled', value: 'cancelled' },
    { label: 'Running', value: 'running' },
];

export const FEEDBACK_OPTIONS = [
    { label: 'Any feedback', value: '' },
    { label: 'Not helpful', value: 'negative' },
    { label: 'Helpful', value: 'positive' },
];

export const FILTER_FIELDS = ['search', 'status', 'task_status', 'feedback', 'degraded', 'truncated', 'provider', 'model', 'company_uuid', 'created_by_uuid', 'from', 'to'];

/**
 * Reads a value from a Select option, an input event, or a plain value.
 */
export function normalizeValue(value) {
    return value?.value ?? value?.target?.value ?? value ?? '';
}

/**
 * Drops empty values so they are not sent as query parameters.
 */
export function compactQuery(query = {}) {
    return Object.entries(query).reduce((params, [key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params[key] = value;
        }

        return params;
    }, {});
}

/**
 * The ModelSelect sources for the organization and user pickers.
 */
export function adminSources(fetch) {
    const source = (path) => ({ query: (modelName, query = {}) => fetch.get(path, query, { namespace: 'ai/int/v1' }) });

    return { companies: source('admin/companies'), users: source('admin/users') };
}

/**
 * Filter state shared by the AI log and usage views. Every setter replaces tracked fields, so a
 * component can read `filters.x` in a template and reload when they change.
 */
export default class AiAdminFilters {
    @tracked search = '';
    @tracked status = '';
    @tracked task_status = '';
    @tracked feedback = '';
    @tracked degraded = '';
    @tracked truncated = '';
    @tracked provider = '';
    @tracked model = '';
    @tracked company_uuid = '';
    @tracked created_by_uuid = '';
    @tracked from = '';
    @tracked to = '';
    @tracked selectedCompany = null;
    @tracked selectedUser = null;
    @tracked dateRange = null;

    set(field, value) {
        this[field] = normalizeValue(value);
    }

    /**
     * A model belongs to one provider, so changing the provider clears it.
     */
    setProvider(value) {
        this.provider = normalizeValue(value);
        this.model = '';
    }

    /**
     * A user belongs to an organization, so changing the organization clears the user.
     */
    setCompany(company) {
        this.selectedCompany = company ?? null;
        this.company_uuid = company?.uuid ?? company?.id ?? '';
        this.selectedUser = null;
        this.created_by_uuid = '';
    }

    setUser(user) {
        this.selectedUser = user ?? null;
        this.created_by_uuid = user?.uuid ?? user?.id ?? '';
    }

    /**
     * Accepts the DatePicker payload: a range, a single day, a string, or nothing to clear.
     */
    setDateRange({ formattedDate } = {}) {
        const dates = Array.isArray(formattedDate) ? formattedDate.filter(Boolean) : formattedDate ? [formattedDate] : [];

        this.dateRange = dates.length ? dates : null;
        this.from = dates[0] ?? '';
        this.to = dates[1] ?? dates[0] ?? '';
    }

    setRange(from, to) {
        this.from = from ?? '';
        this.to = to ?? '';
        this.dateRange = from ? [from, to] : null;
    }

    toggle(field) {
        this[field] = this[field] ? '' : '1';
    }

    clear() {
        FILTER_FIELDS.forEach((field) => (this[field] = ''));
        this.selectedCompany = null;
        this.selectedUser = null;
        this.dateRange = null;
    }

    /**
     * How many of the given fields are set, for the "More filters" badge.
     */
    activeCount(fields = FILTER_FIELDS) {
        const keys = fields.flatMap((field) => (field === 'company' ? ['company_uuid'] : field === 'user' ? ['created_by_uuid'] : field === 'date' ? ['from'] : [field]));

        return keys.filter((key) => Boolean(this[key])).length;
    }

    get hasAny() {
        return this.activeCount() > 0;
    }

    toQuery(fields = FILTER_FIELDS, extra = {}) {
        return compactQuery({ ...Object.fromEntries(fields.map((field) => [field, this[field]])), ...extra });
    }

    providerOptions(metadata) {
        return [{ label: 'Any provider', value: '' }, ...(metadata?.providers ?? [])];
    }

    modelOptions(metadata) {
        const provider = (metadata?.providers ?? []).find((option) => option.value === this.provider);

        return [{ label: 'Any model', value: '' }, ...(provider?.models ?? [])];
    }

    get userQuery() {
        return this.company_uuid ? { company_uuid: this.company_uuid } : {};
    }
}
