import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import AiAdminFilters, { adminSources, compactQuery } from '../../utils/ai-admin-filters';

export const RANGE_PRESETS = [
    { key: '7d', label: '7 days', days: 7 },
    { key: '30d', label: '30 days', days: 30 },
    { key: '90d', label: '90 days', days: 90 },
    { key: 'all', label: 'All time', days: null },
];

function isoDate(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * The range covering the last `days` days, today included.
 */
export function presetRange(days, today = new Date()) {
    if (!days) {
        return { from: '', to: '' };
    }

    const start = new Date(today);
    start.setDate(start.getDate() - (days - 1));

    return { from: isoDate(start), to: isoDate(today) };
}

/**
 * AI usage for a period: headline numbers, activity per day, and rankings by who and what used it.
 * Opens on the last 30 days; filters apply as they change.
 */
export default class AdminAiUsageAnalyticsComponent extends Component {
    @service fetch;
    @service notifications;

    filters = new AiAdminFilters();
    presets = RANGE_PRESETS;
    toolbarFilters = ['task_status', 'provider', 'model', 'company', 'user', 'date'];

    @tracked usage = null;
    @tracked metadata = { providers: [] };

    constructor() {
        super(...arguments);
        this.sources = adminSources(this.fetch);
        const { from, to } = presetRange(30);
        this.filters.setRange(from, to);
        this.loadConfigMetadata.perform();
        this.loadUsage.perform();
    }

    get summary() {
        return this.usage?.summary ?? {};
    }

    get activePreset() {
        return this.presets.find((preset) => {
            const { from, to } = presetRange(preset.days);

            return from === this.filters.from && to === this.filters.to;
        })?.key;
    }

    get whoTabs() {
        return [
            { key: 'company', label: 'Organization', rows: this.usage?.by_company ?? [] },
            { key: 'user', label: 'User', rows: this.usage?.by_user ?? [] },
        ];
    }

    get whatTabs() {
        return [
            { key: 'provider', label: 'Provider', rows: this.usage?.by_provider ?? [] },
            { key: 'model', label: 'Model', rows: this.usage?.by_model ?? [] },
            { key: 'status', label: 'Status', rows: this.usage?.by_status ?? [] },
        ];
    }

    get query() {
        const f = this.filters;

        return compactQuery({ status: f.task_status, provider: f.provider, model: f.model, company_uuid: f.company_uuid, created_by_uuid: f.created_by_uuid, from: f.from, to: f.to });
    }

    @task *loadConfigMetadata() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *loadUsage() {
        try {
            this.usage = yield this.fetch.get('admin/usage', this.query, { namespace: 'ai/int/v1' });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action filtersChanged(field) {
        // Clearing returns to the default period rather than all time.
        if (field === 'clear') {
            const { from, to } = presetRange(30);
            this.filters.setRange(from, to);
        }

        this.loadUsage.perform();
    }

    @action selectPreset(preset) {
        const { from, to } = presetRange(preset.days);
        this.filters.setRange(from, to);
        this.loadUsage.perform();
    }

    /**
     * Narrows the view to the clicked row of a ranking.
     */
    @action drill(dimension, row) {
        if (dimension === 'company') {
            this.filters.setCompany({ uuid: row.key, name: row.label });
        } else if (dimension === 'user') {
            this.filters.setUser({ uuid: row.key, name: row.label });
        } else if (dimension === 'provider') {
            this.filters.setProvider(row.key);
        } else if (dimension === 'status') {
            this.filters.set('task_status', row.key);
        } else {
            this.filters.set(dimension, row.key);
        }

        this.loadUsage.perform();
    }
}
