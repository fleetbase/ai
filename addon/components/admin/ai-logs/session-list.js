import Component from '@glimmer/component';
import { action } from '@ember/object';
import { formatCompact, formatNumber, formatRelative } from '../../../utils/ai-admin-format';

/**
 * The conversation list on the left of the log view: two lines per conversation, with the signals a
 * reviewer scans for (negative feedback, failures, degraded or cut-off answers).
 */
export default class AdminAiLogsSessionListComponent extends Component {
    get rows() {
        return (this.args.sessions ?? []).map((session) => {
            const id = session.uuid ?? session.id;

            return {
                session,
                id,
                title: session.title || 'New AI chat',
                isSelected: id === this.args.selectedId,
                when: formatRelative(session.last_message_at ?? session.created_at),
                whenTitle: session.last_message_at ?? session.created_at,
                owner: [session.company?.name, session.created_by?.name ?? session.created_by?.email].filter(Boolean).join(' · '),
                turns: session.tasks_count ?? 0,
                tokens: formatCompact(session.total_tokens),
                tokensTitle: `${formatNumber(session.total_tokens)} tokens`,
                negative: session.negative_feedback_count ?? 0,
                failed: session.failed_count ?? 0,
                flagged: session.flagged_count ?? 0,
                isActive: session.status === 'active',
            };
        });
    }

    @action select(session) {
        this.args.onSelect?.(session);
    }

    /**
     * Up and Down move the selection while the list has focus.
     */
    @action navigate(event) {
        if (!['ArrowDown', 'ArrowUp'].includes(event.key)) {
            return;
        }

        const rows = this.rows;
        if (!rows.length) {
            return;
        }

        event.preventDefault();
        const current = rows.findIndex((row) => row.isSelected);
        const next = current === -1 ? 0 : Math.min(Math.max(current + (event.key === 'ArrowDown' ? 1 : -1), 0), rows.length - 1);

        if (next !== current) {
            this.select(rows[next].session);
            event.currentTarget.querySelectorAll('[role="option"]')[next]?.scrollIntoView?.({ block: 'nearest' });
        }
    }
}
