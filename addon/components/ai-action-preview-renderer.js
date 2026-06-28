import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { warn } from '@ember/debug';

export default class AiActionPreviewRendererComponent extends Component {
    @service('universe/registry-service') registryService;

    get registry() {
        return `ai:action-preview:${this.args.preview?.key ?? this.args.preview?.action}`;
    }

    get customRenderer() {
        return this.renderableComponents[0];
    }

    get hasCustomRenderer() {
        return Boolean(this.customRenderer);
    }

    get renderableComponents() {
        return this.registryService?.getRenderableComponents?.(this.registry) ?? [];
    }

    get context() {
        return {
            task: this.args.task,
            preview: this.args.preview,
            onApply: this.args.onApply,
            onCancel: this.args.onCancel,
            onRefresh: this.args.onRefresh,
            isApplying: this.args.isApplying,
            isCancelling: this.args.isCancelling,
            isRefreshing: this.args.isRefreshing,
        };
    }

    @action warnIfMissingRenderer() {
        if (this.hasCustomRenderer) {
            return;
        }

        warn(`No Fleetbase AI action preview renderer registered for "${this.registry}". Falling back to the generic action card.`, {
            id: 'fleetbase-ai.missing-action-preview-renderer',
        });
    }
}
