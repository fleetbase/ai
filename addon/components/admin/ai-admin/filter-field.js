import Component from '@glimmer/component';
import { action } from '@ember/object';
import { FEEDBACK_OPTIONS, SESSION_STATUS_OPTIONS, TASK_STATUS_OPTIONS } from '../../../utils/ai-admin-filters';

/**
 * One filter control in the AI admin toolbar, chosen by `@field`. Every change updates `@filters` and
 * then calls `@onChange(field)`, so the view decides when to reload.
 */
export default class AdminAiAdminFilterFieldComponent extends Component {
    sessionStatusOptions = SESSION_STATUS_OPTIONS;
    taskStatusOptions = TASK_STATUS_OPTIONS;
    feedbackOptions = FEEDBACK_OPTIONS;

    get providerOptions() {
        return this.args.filters.providerOptions(this.args.metadata);
    }

    get modelOptions() {
        return this.args.filters.modelOptions(this.args.metadata);
    }

    changed(field) {
        this.args.onChange?.(field);
    }

    @action set(field, value) {
        this.args.filters.set(field, value);
        this.changed(field);
    }

    @action setFromInput(field, event) {
        this.set(field, event.target.value);
    }

    @action toggle(field) {
        this.args.filters.toggle(field);
        this.changed(field);
    }

    @action setProvider(value) {
        this.args.filters.setProvider(value);
        this.changed('provider');
    }

    @action setCompany(company) {
        this.args.filters.setCompany(company);
        this.changed('company');
    }

    @action setUser(user) {
        this.args.filters.setUser(user);
        this.changed('user');
    }

    @action setDateRange(selection) {
        this.args.filters.setDateRange(selection);
        this.changed('date');
    }
}
