import Component from '@glimmer/component';
import { LOCALE, formatCompact, formatDay, formatNumber } from '../../../utils/ai-admin-format';

/**
 * Answers and tokens per day. `<Chart>` draws once when inserted, so the parent re-renders this
 * component after every load rather than updating it in place.
 */
export default class AdminAiUsageTrendChartComponent extends Component {
    get days() {
        return this.args.days ?? [];
    }

    get labels() {
        return this.days.map((row) => formatDay(row.day));
    }

    get datasets() {
        return [
            {
                type: 'bar',
                label: 'Answers',
                data: this.days.map((row) => Number(row.task_count ?? 0)),
                yAxisID: 'y',
                backgroundColor: 'rgba(59, 130, 246, 0.55)',
                borderRadius: 3,
                order: 2,
            },
            {
                type: 'line',
                label: 'Tokens',
                data: this.days.map((row) => Number(row.total_tokens ?? 0)),
                yAxisID: 'y1',
                borderColor: '#8b5cf6',
                backgroundColor: '#8b5cf6',
                borderWidth: 2,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 4,
                order: 1,
            },
        ];
    }

    get options() {
        const dark = typeof document !== 'undefined' && document.body?.dataset?.theme === 'dark';
        const text = dark ? '#9ca3af' : '#6b7280';
        const grid = dark ? 'rgba(75, 85, 99, 0.35)' : 'rgba(229, 231, 235, 0.9)';

        return {
            // chart.js formats ticks with Intl; give it the browser's language explicitly.
            locale: LOCALE,
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { color: text, boxWidth: 10, boxHeight: 10 } },
                tooltip: { callbacks: { label: (item) => `${item.dataset.label}: ${formatNumber(item.raw)}` } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: text, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                y: { beginAtZero: true, position: 'left', grid: { color: grid }, ticks: { color: text, precision: 0 }, title: { display: true, text: 'Answers', color: text } },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { color: text, callback: (value) => formatCompact(value) },
                    title: { display: true, text: 'Tokens', color: text },
                },
            },
        };
    }
}
