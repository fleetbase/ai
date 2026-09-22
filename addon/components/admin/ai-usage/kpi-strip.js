import Component from '@glimmer/component';
import { formatCompact, formatNumber, formatPercent } from '../../../utils/ai-admin-format';

/**
 * Headline numbers for the selected period. A tile whose value the server did not send is left out,
 * so the strip still works against an older server.
 */
export default class AdminAiUsageKpiStripComponent extends Component {
    get summary() {
        return this.args.summary ?? {};
    }

    has(key) {
        return this.summary[key] !== undefined && this.summary[key] !== null;
    }

    get tiles() {
        const s = this.summary;
        const tasks = Number(s.task_count ?? 0);
        const flagged = Number(s.degraded_count ?? 0) + Number(s.truncated_count ?? 0);

        return [
            { key: 'tasks', label: 'Answers', icon: 'comment-dots', value: formatNumber(s.task_count), show: true },
            { key: 'sessions', label: 'Conversations', icon: 'comments', value: formatNumber(s.session_count), show: this.has('session_count') },
            {
                key: 'tokens',
                label: 'Total tokens',
                icon: 'coins',
                value: formatCompact(s.total_tokens),
                title: `${formatNumber(s.total_tokens)} tokens`,
                sub: `${formatCompact(s.input_tokens)} in · ${formatCompact(s.output_tokens)} out`,
                show: true,
            },
            { key: 'average', label: 'Avg tokens per answer', icon: 'scale-balanced', value: tasks ? formatNumber(Math.round(Number(s.total_tokens ?? 0) / tasks)) : '—', show: true },
            {
                key: 'success',
                label: 'Success rate',
                icon: 'circle-check',
                value: formatPercent(s.completed_count, tasks) ?? '—',
                sub: `${formatNumber(s.completed_count)} completed`,
                show: true,
            },
            { key: 'failed', label: 'Failed', icon: 'circle-xmark', value: formatNumber(s.failed_count), tone: Number(s.failed_count) > 0 ? 'is-danger' : '', show: true },
            {
                key: 'feedback',
                label: 'Not helpful',
                icon: 'thumbs-down',
                value: formatNumber(s.negative_feedback_count),
                sub: `${formatNumber(s.positive_feedback_count)} helpful`,
                tone: Number(s.negative_feedback_count) > 0 ? 'is-danger' : '',
                show: this.has('negative_feedback_count'),
            },
            {
                key: 'flagged',
                label: 'Degraded / cut off',
                icon: 'triangle-exclamation',
                value: `${formatNumber(s.degraded_count)} / ${formatNumber(s.truncated_count)}`,
                tone: flagged > 0 ? 'is-warning' : '',
                show: this.has('degraded_count'),
            },
        ].filter((tile) => tile.show);
    }
}
