import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class AiHeaderTrayButtonComponent extends Component {
    @service ai;
    @service aiEvents;

    constructor() {
        super(...arguments);
        this.ai.loadStatus.perform();
    }

    @action togglePrompt() {
        this.aiEvents.togglePrompt();
    }
}
