import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';

export default class AdminAiUsageAnalyticsComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked filters = {
        status: '',
        provider: '',
        model: '',
        company_uuid: '',
        created_by_uuid: '',
        from: '',
        to: '',
    };
    @tracked usage = {
        summary: {},
        by_company: [],
        by_user: [],
        by_provider: [],
        by_model: [],
        by_status: [],
        by_day: [],
    };
    @tracked metadata = { providers: [] };
    @tracked selectedCompany = null;
    @tracked selectedUser = null;
    @tracked dateRange = null;

    statusOptions = [
        { label: 'Any status', value: '' },
        { label: 'Completed', value: 'completed' },
        { label: 'Failed', value: 'failed' },
        { label: 'Cancelled', value: 'cancelled' },
        { label: 'Running', value: 'running' },
    ];

    constructor() {
        super(...arguments);
        this.loadConfigMetadata.perform();
        this.loadUsage.perform();
    }

    get summary() {
        return this.usage.summary ?? {};
    }

    get usageCards() {
        return [
            { label: 'Tasks', value: this.summary.task_count ?? 0, icon: 'list-check' },
            { label: 'Total tokens', value: this.summary.total_tokens ?? 0, icon: 'coins' },
            { label: 'Input tokens', value: this.summary.input_tokens ?? 0, icon: 'arrow-right-to-bracket' },
            { label: 'Output tokens', value: this.summary.output_tokens ?? 0, icon: 'arrow-right-from-bracket' },
            { label: 'Completed', value: this.summary.completed_count ?? 0, icon: 'circle-check' },
            { label: 'Failed', value: this.summary.failed_count ?? 0, icon: 'triangle-exclamation' },
        ];
    }

    get providerOptions() {
        return [{ label: 'Any provider', value: '' }, ...(this.metadata.providers ?? [])];
    }

    get selectedProviderMetadata() {
        return (this.metadata.providers ?? []).find((provider) => provider.value === this.filters.provider);
    }

    get modelOptions() {
        return [{ label: 'Any model', value: '' }, ...(this.selectedProviderMetadata?.models ?? [])];
    }

    get userQuery() {
        return this.filters.company_uuid ? { company_uuid: this.filters.company_uuid } : {};
    }

    get companySource() {
        return {
            query: (modelName, query = {}) => this.fetch.get('admin/companies', query, { namespace: 'ai/int/v1' }),
        };
    }

    get userSource() {
        return {
            query: (modelName, query = {}) => this.fetch.get('admin/users', query, { namespace: 'ai/int/v1' }),
        };
    }

    @task *loadConfigMetadata() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadUsage() {
        try {
            this.usage = yield this.fetch.get('admin/usage', this.cleanFilters(this.filters), { namespace: 'ai/int/v1' });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setFilter(field, value) {
        this.filters = {
            ...this.filters,
            [field]: this.normalizeValue(value),
        };
    }

    @action setFilterFromInput(field, event) {
        this.setFilter(field, event.target.value);
    }

    @action setProvider(value) {
        const provider = this.normalizeValue(value);
        this.filters = {
            ...this.filters,
            provider,
            model: '',
        };
    }

    @action setCompany(company) {
        this.selectedCompany = company ?? null;
        this.selectedUser = null;
        this.filters = {
            ...this.filters,
            company_uuid: company?.uuid ?? company?.id ?? '',
            created_by_uuid: '',
        };
    }

    @action setUser(user) {
        this.selectedUser = user ?? null;
        this.filters = {
            ...this.filters,
            created_by_uuid: user?.uuid ?? user?.id ?? '',
        };
    }

    @action setDateRange({ formattedDate } = {}) {
        this.dateRange = formattedDate;

        if (Array.isArray(formattedDate) && formattedDate.length >= 2) {
            this.filters = {
                ...this.filters,
                from: formattedDate[0],
                to: formattedDate[1],
            };
            return;
        }

        if (Array.isArray(formattedDate) && formattedDate.length === 1) {
            this.filters = {
                ...this.filters,
                from: formattedDate[0],
                to: formattedDate[0],
            };
            return;
        }

        if (typeof formattedDate === 'string' && formattedDate) {
            this.filters = {
                ...this.filters,
                from: formattedDate,
                to: formattedDate,
            };
            return;
        }

        this.filters = {
            ...this.filters,
            from: '',
            to: '',
        };
    }

    @action clearFilters() {
        this.filters = {
            status: '',
            provider: '',
            model: '',
            company_uuid: '',
            created_by_uuid: '',
            from: '',
            to: '',
        };
        this.selectedCompany = null;
        this.selectedUser = null;
        this.dateRange = null;
        this.loadUsage.perform();
    }

    cleanFilters(filters) {
        return Object.entries(filters).reduce((params, [key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                params[key] = value;
            }

            return params;
        }, {});
    }

    normalizeValue(value) {
        return value?.value ?? value?.target?.value ?? value;
    }
}
