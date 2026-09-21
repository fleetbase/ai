import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import AiAdminFilters, { adminSources } from '../../utils/ai-admin-filters';

const PAGE_SIZE = 50;
const SEARCH_DEBOUNCE_MS = 350;
const LIST_FIELDS = ['search', 'status', 'task_status', 'feedback', 'degraded', 'truncated', 'provider', 'model', 'company_uuid', 'created_by_uuid', 'from', 'to'];

/**
 * AI conversation logs: a filterable list of conversations beside the selected one, read as a transcript.
 * Filters apply as they change; typing in search waits for a pause.
 */
export default class AdminAiAuditLogsComponent extends Component {
    @service fetch;
    @service notifications;

    filters = new AiAdminFilters();
    primaryFilters = ['search', 'feedback', 'review', 'date'];
    secondaryFilters = ['session_status', 'task_status', 'provider', 'model', 'company', 'user'];

    @tracked sessions = [];
    @tracked page = 1;
    @tracked hasMore = false;
    @tracked selectedSession = null;
    @tracked selectedId = null;
    @tracked metadata = { providers: [] };

    constructor() {
        super(...arguments);
        this.sources = adminSources(this.fetch);
        this.loadConfigMetadata.perform();
        this.loadSessions.perform();
    }

    get isFiltered() {
        return this.filters.hasAny;
    }

    get hasSelection() {
        return Boolean(this.selectedId);
    }

    @task *loadConfigMetadata() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *loadSessions(append = false) {
        const page = append ? this.page + 1 : 1;

        try {
            const response = yield this.fetch.get('admin/sessions', this.filters.toQuery(LIST_FIELDS, { limit: PAGE_SIZE, page }), { namespace: 'ai/int/v1' });
            const sessions = response.sessions ?? [];

            this.sessions = append ? [...this.sessions, ...sessions] : sessions;
            this.page = response.meta?.page ?? page;
            this.hasMore = response.meta?.has_more === true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *searchSessions() {
        yield timeout(SEARCH_DEBOUNCE_MS);
        yield this.loadSessions.perform();
    }

    @task({ restartable: true }) *loadSession(session) {
        const id = session?.uuid ?? session?.id;
        if (!id) {
            return;
        }

        this.selectedId = id;

        try {
            const response = yield this.fetch.get(`admin/sessions/${id}`, {}, { namespace: 'ai/int/v1' });
            this.selectedSession = response.session;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Exports the conversations the list shows, or one conversation. The server reads the session status
     * as `session_status`, since `status` on an export means the answer status.
     */
    @task *exportLogs(format, sessionUuid = null) {
        const query = sessionUuid
            ? { ai_session_uuid: sessionUuid, format }
            : this.filters.toQuery(
                  LIST_FIELDS.filter((field) => field !== 'status'),
                  { session_status: this.filters.status, format }
              );

        try {
            yield this.fetch.download('admin/export', query, { namespace: 'ai/int/v1', fileName: `fleetbase-ai-logs.${format}` });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action filtersChanged(field) {
        if (field === 'search') {
            this.searchSessions.perform();
            return;
        }

        this.searchSessions.cancelAll();
        this.loadSessions.perform();
    }

    @action loadMore() {
        this.loadSessions.perform(true);
    }

    @action selectSession(session) {
        this.loadSession.perform(session);
    }

    @action closeSession() {
        this.loadSession.cancelAll();
        this.selectedId = null;
        this.selectedSession = null;
    }

    @action export(format, sessionUuid = null, dropdown = null) {
        dropdown?.actions?.close?.();
        this.exportLogs.perform(format, sessionUuid);
    }
}
