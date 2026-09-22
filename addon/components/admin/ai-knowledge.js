import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

/**
 * Status of the documentation Fleetbase AI answers from, with controls to refresh it.
 */
export default class AdminAiKnowledgeComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked knowledge = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get audiences() {
        const labels = { end_user: 'Organization users', developer: 'Developers', system_admin: 'System administrators' };

        return Object.entries(this.knowledge?.by_audience ?? {}).map(([key, total]) => ({ label: labels[key] ?? key, total }));
    }

    get modules() {
        return Object.entries(this.knowledge?.by_module ?? {}).map(([module, total]) => ({ module, total }));
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('admin/knowledge', {}, { namespace: 'ai/int/v1' });
            this.knowledge = response.knowledge;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *sync(source = 'site') {
        try {
            yield this.fetch.post('admin/knowledge/sync', { source }, { namespace: 'ai/int/v1' });
            this.notifications.success(source === 'snapshot' ? 'Loading the packaged documentation snapshot.' : 'Documentation sync queued. It can take a few minutes.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
