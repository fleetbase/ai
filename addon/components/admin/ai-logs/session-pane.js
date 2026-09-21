import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatNumber, statusType } from '../../../utils/ai-admin-format';

/**
 * The selected conversation, read as a transcript.
 */
export default class AdminAiLogsSessionPaneComponent extends Component {
    @service notifications;

    get session() {
        return this.args.session;
    }

    get tasks() {
        return this.session?.tasks ?? [];
    }

    get title() {
        return this.session?.title || 'New AI chat';
    }

    get statusType() {
        return statusType(this.session?.status);
    }

    get owner() {
        return [this.session?.company?.name, this.session?.created_by?.name].filter(Boolean).join(' · ');
    }

    get ownerTitle() {
        return this.session?.created_by?.email ?? null;
    }

    get totalTokens() {
        return formatNumber(this.session?.total_tokens);
    }

    get tokenSplit() {
        const sum = (key) => this.tasks.reduce((total, task) => total + Number(task[key] ?? 0), 0);

        return `${formatNumber(sum('input_tokens'))} in · ${formatNumber(sum('output_tokens'))} out`;
    }

    get turns() {
        const count = this.session?.tasks_count ?? this.tasks.length;

        return `${count} turn${count === 1 ? '' : 's'}`;
    }

    @action async copyId() {
        const id = this.session?.uuid;

        try {
            await navigator.clipboard.writeText(id);
            this.notifications.success('Conversation ID copied.');
        } catch {
            this.notifications.success(`Conversation ID: ${id}`);
        }
    }
}
