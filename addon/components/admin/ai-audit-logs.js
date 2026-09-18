import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';

export default class AdminAiAuditLogsComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked filters = this.emptyFilters();
    @tracked page = 1;
    @tracked hasMore = false;
    @tracked expandedStep = null;
    @tracked sessions = [];
    @tracked selectedSession = null;
    @tracked selectedTask = null;
    @tracked canRevealContent = false;
    @tracked metadata = { providers: [] };
    @tracked selectedCompany = null;
    @tracked selectedUser = null;
    @tracked dateRange = null;

    sessionStatusOptions = [
        { label: 'Any session status', value: '' },
        { label: 'Active', value: 'active' },
        { label: 'Ended', value: 'ended' },
    ];

    taskStatusOptions = [
        { label: 'Any answer status', value: '' },
        { label: 'Answered', value: 'answered' },
        { label: 'Applied', value: 'applied' },
        { label: 'Failed', value: 'failed' },
        { label: 'Apply failed', value: 'apply_failed' },
        { label: 'Cancelled', value: 'cancelled' },
        { label: 'Running', value: 'running' },
    ];

    feedbackOptions = [
        { label: 'Any feedback', value: '' },
        { label: 'Not helpful', value: 'negative' },
        { label: 'Helpful', value: 'positive' },
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
        return (this.selectedTask?.steps ?? []).map((step) => ({
            ...step,
            key: step.uuid ?? step.id,
            label: step.type === 'tool_call' ? `Tool: ${step.input?.name ?? step.tool ?? 'unknown'}` : step.type,
            isExpanded: this.expandedStep === (step.uuid ?? step.id),
            inputJson: this.formatJson(step.input),
            outputJson: this.formatJson(step.output),
            errorJson: this.formatJson(step.error),
        }));
    }

    get hasPreviousPage() {
        return this.page > 1;
    }

    get selectedTaskSummary() {
        return this.selectedTask?.metadata ?? {};
    }

    @task *loadSessions(page = 1) {
        try {
            const response = yield this.fetch.get('admin/sessions', this.cleanFilters({ ...this.filters, limit: 50, page }), { namespace: 'ai/int/v1' });
            this.sessions = response.sessions ?? [];
            this.page = response.meta?.page ?? page;
            this.hasMore = response.meta?.has_more === true;
            this.canRevealContent = response.meta?.can_reveal_content === true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *exportLogs(format = 'jsonl') {
        try {
            yield this.fetch.download('admin/export', this.cleanFilters({ ...this.filters, status: this.filters.task_status, format }), {
                namespace: 'ai/int/v1',
                fileName: `fleetbase-ai-logs.${format}`,
            });
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
        this.filters = this.emptyFilters();
        this.selectedCompany = null;
        this.selectedUser = null;
        this.dateRange = null;
        this.loadSessions.perform(1);
    }

    @action toggleFilter(field) {
        this.filters = {
            ...this.filters,
            [field]: this.filters[field] ? '' : '1',
        };
    }

    @action search() {
        this.loadSessions.perform(1);
    }

    @action nextPage() {
        this.loadSessions.perform(this.page + 1);
    }

    @action previousPage() {
        this.loadSessions.perform(Math.max(this.page - 1, 1));
    }

    @action toggleStep(step) {
        const id = step?.uuid ?? step?.id;
        this.expandedStep = this.expandedStep === id ? null : id;
    }

    formatJson(value) {
        return value === null || value === undefined ? '' : JSON.stringify(value, null, 2);
    }

    emptyFilters() {
        return {
            search: '',
            status: '',
            task_status: '',
            feedback: '',
            degraded: '',
            truncated: '',
            provider: '',
            model: '',
            company_uuid: '',
            created_by_uuid: '',
            from: '',
            to: '',
        };
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
