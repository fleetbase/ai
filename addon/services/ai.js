import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class AiService extends Service {
    @service fetch;
    @service router;
    @tracked isOpen = false;
    @tracked activeTask = null;
    @tracked tasks = [];
    @tracked sessions = [];
    @tracked currentSession = null;
    @tracked sessionTasks = [];
    @tracked config = { enabled: false };
    @tracked metadata = { providers: [] };

    get isEnabled() {
        return this.config?.enabled === true;
    }

    toggle() {
        if (!this.isEnabled) {
            this.close();
            return;
        }

        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        if (!this.isEnabled) {
            return;
        }

        this.isOpen = true;
        this.loadLatestSession.perform();
    }

    close() {
        this.isOpen = false;
    }

    applyConfig(config = {}, metadata = this.metadata) {
        this.config = {
            ...this.config,
            ...config,
        };
        this.metadata = metadata ?? this.metadata;

        if (!this.isEnabled) {
            this.close();
        }
    }

    @task *loadConfig() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.applyConfig(response.config ?? {}, response.metadata ?? this.metadata);

            return this.config;
        } catch (error) {
            this.applyConfig({ enabled: false });

            return this.config;
        }
    }

    @task *submit(prompt, attachments = []) {
        if (!this.isEnabled) {
            return null;
        }

        const pendingTask = {
            uuid: `pending-${Date.now()}`,
            prompt,
            status: 'running',
            isPending: true,
            ai_session_uuid: this.currentSession?.uuid,
            metadata: {
                attachments,
            },
        };

        this.sessionTasks = [...this.sessionTasks, pendingTask];

        try {
            const response = yield this.fetch.post(
                'tasks',
                {
                    prompt,
                    session_uuid: this.currentSession?.uuid,
                    attachments: attachments.map((attachment) => attachment.id ?? attachment.uuid ?? attachment.public_id).filter(Boolean),
                    task_type: 'prompt',
                    context: {
                        route: this.router.currentRouteName,
                        url: window.location.href,
                    },
                },
                { namespace: 'ai/int/v1' }
            );

            this.activeTask = response.task;
            this.applyTaskToSession(response.task, pendingTask.uuid);
            if (response.task?.session) {
                this.currentSession = response.task.session;
            }
            yield this.loadSessions.perform();

            return this.activeTask;
        } catch (error) {
            this.sessionTasks = this.sessionTasks.filter((task) => task.uuid !== pendingTask.uuid);
            throw error;
        }
    }

    @task *loadHistory() {
        const response = yield this.fetch.get('tasks', { limit: 20, mine: true }, { namespace: 'ai/int/v1' });
        this.tasks = response.tasks ?? [];

        return this.tasks;
    }

    @task *loadSessions() {
        const response = yield this.fetch.get('sessions', { limit: 30, mine: true }, { namespace: 'ai/int/v1' });
        this.sessions = response.sessions ?? [];

        return this.sessions;
    }

    @task *loadLatestSession() {
        const sessions = yield this.loadSessions.perform();
        const latestActiveSession = sessions.find((session) => session.status === 'active');

        if (latestActiveSession) {
            yield this.loadSession.perform(latestActiveSession);
        } else {
            this.currentSession = null;
            this.sessionTasks = [];
        }

        return this.currentSession;
    }

    @task *loadSession(session) {
        const id = session?.uuid ?? session?.id;
        if (!id) {
            this.currentSession = null;
            this.sessionTasks = [];

            return null;
        }

        const response = yield this.fetch.get(`sessions/${id}`, {}, { namespace: 'ai/int/v1' });
        this.currentSession = response.session;
        this.sessionTasks = response.session?.tasks ?? [];

        return this.currentSession;
    }

    @task *startSession() {
        const response = yield this.fetch.post('sessions', {}, { namespace: 'ai/int/v1' });
        this.currentSession = response.session;
        this.sessionTasks = response.session?.tasks ?? [];
        yield this.loadSessions.perform();

        return this.currentSession;
    }

    @task *endSession() {
        const id = this.currentSession?.uuid ?? this.currentSession?.id;
        if (!id) {
            this.currentSession = null;
            this.sessionTasks = [];

            return null;
        }

        yield this.fetch.post(`sessions/${id}/end`, {}, { namespace: 'ai/int/v1' });
        this.currentSession = null;
        this.sessionTasks = [];
        yield this.loadSessions.perform();

        return this.currentSession;
    }

    @task *deleteSession(session) {
        const id = session?.uuid ?? session?.id;
        if (!id) {
            return null;
        }

        yield this.fetch.delete(`sessions/${id}`, {}, { namespace: 'ai/int/v1' });

        this.sessions = this.sessions.filter((existingSession) => (existingSession.uuid ?? existingSession.id) !== id);

        if ((this.currentSession?.uuid ?? this.currentSession?.id) === id) {
            this.currentSession = null;
            this.sessionTasks = [];
        }

        yield this.loadSessions.perform();

        return session;
    }

    @task *applyTask(task, actionKey = null, input = {}) {
        const response = yield this.fetch.post(
            `tasks/${task.id ?? task.uuid}/apply`,
            {
                action_key: actionKey,
                input,
            },
            { namespace: 'ai/int/v1' }
        );

        this.activeTask = response.task;
        this.applyTaskToSession(response.task);
        yield this.loadSessions.perform();

        return this.activeTask;
    }

    @task *refreshTaskPreview(task, actionKey = null, input = {}) {
        const response = yield this.fetch.post(
            `tasks/${task.id ?? task.uuid}/preview`,
            {
                action_key: actionKey,
                input,
            },
            { namespace: 'ai/int/v1' }
        );

        this.activeTask = response.task;
        this.applyTaskToSession(response.task);
        yield this.loadSessions.perform();

        return this.activeTask;
    }

    @task *cancelTask(task) {
        const response = yield this.fetch.post(`tasks/${task.id ?? task.uuid}/cancel`, {}, { namespace: 'ai/int/v1' });

        this.activeTask = response.task;
        this.applyTaskToSession(response.task);
        yield this.loadSessions.perform();

        return this.activeTask;
    }

    applyTaskToSession(task, pendingUuid = null) {
        if (!task) {
            return;
        }

        const taskId = task.uuid ?? task.id;
        let didReplace = false;
        let tasks = this.sessionTasks
            .filter((existing) => {
                const existingId = existing.uuid ?? existing.id;

                return !pendingUuid || existingId !== pendingUuid;
            })
            .map((existing) => {
                const existingId = existing.uuid ?? existing.id;
                if (existingId === taskId) {
                    didReplace = true;

                    return task;
                }

                return existing;
            });

        if (!didReplace) {
            tasks = [...tasks, task];
        }

        this.sessionTasks = tasks;
    }
}
