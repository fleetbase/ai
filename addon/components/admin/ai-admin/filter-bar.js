import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * The compact toolbar at the top of the AI admin views. `@primary` fields sit in the toolbar itself,
 * `@secondary` fields in a second row opened with "More filters", whose count shows how many of them
 * are set. Changes are reported through `@onChange(field)`; the view applies them.
 */
export default class AdminAiAdminFilterBarComponent extends Component {
    @tracked showMore = false;

    get secondary() {
        return this.args.secondary ?? [];
    }

    get secondaryActiveCount() {
        return this.args.filters.activeCount(this.secondary.map((field) => (field === 'review' ? ['degraded', 'truncated'] : field === 'session_status' ? 'status' : field)).flat());
    }

    get canClear() {
        return this.args.filters.hasAny;
    }

    @action toggleMore() {
        this.showMore = !this.showMore;
    }

    @action clear() {
        this.args.filters.clear();
        this.args.onChange?.('clear');
    }
}
