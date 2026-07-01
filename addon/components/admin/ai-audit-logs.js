import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';

export default class AdminAiAuditLogsComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked filters = {
        search: '',
        status: '',
        provider: '',
        model: '',
        company_uuid: '',
        created_by_uuid: '',
        from: '',
        to: '',
    };
    @tracked sessions = [];
    @tracked selectedSession = null;
    @tracked selectedTask = null;
    @tracked canRevealContent = false;
    @tracked metadata = { providers: [] };
    @tracked selectedCompany = null;
    @tracked selectedUser = null;
    @tracked dateRange = null;

    statusOptions = [
        { label: 'Any status', value: '' },
        { label: 'Active', value: 'active' },
        { label: 'Ended', value: 'ended' },
        { label: 'Completed', value: 'completed' },
        { label: 'Failed', value: 'failed' },
        { label: 'Cancelled', value: 'cancelled' },
        { label: 'Running', value: 'running' },
    ];

    constructor() {
        super(...arguments);
        this.loadConfigMetadata.perform();
        this.loadSessions.perform();
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

    get selectedSessionTasks() {
        return this.selectedSession?.tasks ?? [];
    }

    get hasSelectedTaskContent() {
        return this.selectedTask && this.selectedTask.content_redacted === false;
    }

    get selectedTaskSteps() {
        return this.selectedTask?.steps ?? [];
    }

    @task *loadSessions() {
        try {
            const response = yield this.fetch.get('admin/sessions', this.cleanFilters({ ...this.filters, limit: 50 }), { namespace: 'ai/int/v1' });
            this.sessions = response.sessions ?? [];
            this.canRevealContent = response.meta?.can_reveal_content === true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadConfigMetadata() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadSession(session) {
        const id = session?.uuid ?? session?.id;
        if (!id) {
            return;
        }

        try {
            const response = yield this.fetch.get(`admin/sessions/${id}`, {}, { namespace: 'ai/int/v1' });
            this.selectedSession = response.session;
            this.canRevealContent = response.meta?.can_reveal_content === true;
            this.selectedTask = this.selectedSessionTasks[0] ?? null;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadTask(task) {
        const id = task?.uuid ?? task?.id;
        if (!id) {
            return;
        }

        try {
            const response = yield this.fetch.get(`admin/tasks/${id}`, {}, { namespace: 'ai/int/v1' });
            this.selectedTask = response.task;
            this.canRevealContent = response.meta?.can_reveal_content === true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *revealTaskContent() {
        const id = this.selectedTask?.uuid ?? this.selectedTask?.id;
        if (!id) {
            return;
        }

        if (!window.confirm('Reveal raw AI prompt and response content? This access will be logged.')) {
            return;
        }

        try {
            const response = yield this.fetch.post(`admin/tasks/${id}/reveal-content`, {}, { namespace: 'ai/int/v1' });
            this.selectedTask = response.task;
            this.notifications.success('AI task content revealed.');
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
            search: '',
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
        this.loadSessions.perform();
    }

    @action selectSession(session) {
        this.loadSession.perform(session);
    }

    @action selectTask(task) {
        this.loadTask.perform(task);
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
