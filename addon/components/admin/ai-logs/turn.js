import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import formatAiResponse from '../../../utils/format-ai-response';
import { formatDuration, formatJson, formatNumber, statusType, stepLabel } from '../../../utils/ai-admin-format';

/**
 * One question and answer in a logged conversation. The audit steps behind the answer (tool calls,
 * provider requests) are fetched the first time they are opened.
 */
export default class AdminAiLogsTurnComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked stepsOpen = false;
    @tracked steps = null;
    @tracked openStepId = null;

    get task() {
        return this.args.task ?? {};
    }

    get metadata() {
        return this.task.metadata ?? {};
    }

    get formattedResponse() {
        return this.task.response ? formatAiResponse(this.task.response) : null;
    }

    get statusType() {
        return statusType(this.task.status);
    }

    get model() {
        return [this.task.provider, this.task.model].filter(Boolean).join(' / ');
    }

    get tokens() {
        return `${formatNumber(this.task.total_tokens)} tokens`;
    }

    get tokensTitle() {
        return `${formatNumber(this.task.input_tokens)} in · ${formatNumber(this.task.output_tokens)} out`;
    }

    get duration() {
        return formatDuration(this.task.started_at, this.task.completed_at);
    }

    get mode() {
        if (this.metadata.mode !== 'tool_calling') {
            return this.metadata.mode ? 'Keyword context' : null;
        }

        const calls = Number(this.metadata.tool_calls ?? 0);

        return calls ? `Tool calling · ${calls} tool call${calls === 1 ? '' : 's'}` : 'Tool calling';
    }

    get flags() {
        const previews = (this.metadata.action_previews ?? []).length;

        return [
            this.metadata.degraded && { label: 'Degraded', icon: 'triangle-exclamation', tone: 'is-warning', title: 'A capability failed, so the answer may be incomplete.' },
            this.metadata.truncated && { label: 'Cut off', icon: 'scissors', tone: 'is-warning', title: 'The answer hit the output limit.' },
            this.metadata.refused && { label: 'Refused', icon: 'ban', tone: 'is-danger', title: 'The model declined to answer.' },
            previews && { label: `${previews} action preview${previews === 1 ? '' : 's'}`, icon: 'wand-magic-sparkles', tone: '', title: 'Action cards shown to the user.' },
        ].filter(Boolean);
    }

    get errorMessage() {
        const error = this.task.error;
        if (!error) {
            return null;
        }

        return typeof error === 'string' ? error : (error.message ?? formatJson(error));
    }

    get feedback() {
        if (Number(this.task.feedback_rating) > 0) {
            return { label: 'Helpful', icon: 'thumbs-up', tone: 'is-success' };
        }

        if (Number(this.task.feedback_rating) < 0) {
            return { label: 'Not helpful', icon: 'thumbs-down', tone: 'is-danger' };
        }

        return null;
    }

    /**
     * Capabilities an engine registered without a tool definition, so the model never saw them.
     */
    get unreachableCapabilities() {
        const capabilities = this.metadata.unreachable_capabilities ?? [];

        return capabilities.length ? capabilities.join(', ') : null;
    }

    get stepsCount() {
        return this.steps ? this.steps.length : Number(this.task.steps_count ?? 0);
    }

    get stepRows() {
        return (this.steps ?? []).map((step) => {
            const id = step.uuid ?? step.id;

            return {
                id,
                label: stepLabel(step),
                status: step.status,
                statusType: statusType(step.status),
                tokens: step.usage?.total_tokens ? formatNumber(step.usage.total_tokens) : null,
                duration: formatDuration(step.started_at, step.completed_at),
                isOpen: this.openStepId === id,
                input: formatJson(step.input),
                output: formatJson(step.output),
                error: formatJson(step.error),
            };
        });
    }

    @task *loadSteps() {
        const id = this.task.uuid ?? this.task.id;

        try {
            const response = yield this.fetch.get(`admin/tasks/${id}`, {}, { namespace: 'ai/int/v1' });
            this.steps = response.task?.steps ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action toggleSteps() {
        this.stepsOpen = !this.stepsOpen;

        if (this.stepsOpen && this.steps === null && !this.loadSteps.isRunning) {
            this.loadSteps.perform();
        }
    }

    @action toggleStep(id) {
        this.openStepId = this.openStepId === id ? null : id;
    }
}
