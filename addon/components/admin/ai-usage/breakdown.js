import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { formatCompact, formatNumber, formatPercent } from '../../../utils/ai-admin-format';

/**
 * A tabbed ranking of usage by one dimension, each row with its share of all tokens. Clicking a row
 * filters the whole view to it.
 */
export default class AdminAiUsageBreakdownComponent extends Component {
    @tracked activeKey = null;

    get tabs() {
        return this.args.tabs ?? [];
    }

    get activeTab() {
        return this.tabs.find((tab) => tab.key === this.activeKey) ?? this.tabs[0];
    }

    get rows() {
        const total = Number(this.args.totalTokens ?? 0);

        return (this.activeTab?.rows ?? []).map((row) => {
            const share = total > 0 ? Math.min((Number(row.total_tokens ?? 0) / total) * 100, 100) : 0;

            return {
                row,
                label: row.label || row.key,
                detail: row.label && row.label !== row.key && row.key !== 'unknown' ? row.key : null,
                tasks: formatNumber(row.task_count),
                tokens: formatCompact(row.total_tokens),
                tokensTitle: `${formatNumber(row.total_tokens)} tokens (${formatNumber(row.input_tokens)} in · ${formatNumber(row.output_tokens)} out)`,
                percent: formatPercent(row.total_tokens, total) ?? '—',
                barStyle: htmlSafe(`width: ${share.toFixed(1)}%`),
                canDrill: Boolean(this.args.onDrill) && row.key && row.key !== 'unknown',
            };
        });
    }

    @action selectTab(key) {
        this.activeKey = key;
    }

    @action drill(item) {
        if (item.canDrill) {
            this.args.onDrill(this.activeTab.key, item.row);
        }
    }
}
