import Service, { inject as service } from '@ember/service';
import Evented from '@ember/object/evented';

export default class AiEventsService extends Service.extend(Evented) {
    @service ai;

    constructor() {
        super(...arguments);
        document.addEventListener('keydown', this.handleGlobalKeydown);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        document.removeEventListener('keydown', this.handleGlobalKeydown);
    }

    handleGlobalKeydown = (event) => {
        const isShortcut = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'j';

        if (isShortcut) {
            event.preventDefault();
            if (!this.ai.isEnabled) {
                return;
            }
            this.togglePrompt();
        }

        if (event.key === 'Escape' && this.ai.isOpen) {
            this.closePrompt();
        }
    };

    openPrompt() {
        if (!this.ai.isEnabled) {
            return;
        }

        this.ai.open();
        this.trigger('prompt.opened');
    }

    closePrompt() {
        this.ai.close();
        this.trigger('prompt.closed');
    }

    togglePrompt() {
        if (!this.ai.isEnabled) {
            this.ai.close();
            return;
        }

        this.ai.toggle();
        this.trigger(this.ai.isOpen ? 'prompt.opened' : 'prompt.closed');
    }
}
