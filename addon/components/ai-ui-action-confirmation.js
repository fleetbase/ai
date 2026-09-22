import Component from '@glimmer/component';
import { task } from 'ember-concurrency';

/**
 * A console action proposed by Fleetbase AI. Nothing happens until the user presses Go.
 */
export default class AiUiActionConfirmationComponent extends Component {
    get isPending() {
        return this.args.action?.status === 'pending';
    }

    get isFailed() {
        return this.args.action?.status === 'failed';
    }

    get isBusy() {
        return this.confirm.isRunning || this.dismiss.isRunning;
    }

    get statusLabel() {
        return { confirmed: 'Opened', dismissed: 'Dismissed', failed: 'Failed' }[this.args.action?.status] ?? null;
    }

    @task *confirm() {
        yield this.args.onConfirm?.(this.args.task, this.args.action);
    }

    @task *dismiss() {
        yield this.args.onDismiss?.(this.args.task, this.args.action);
    }
}
