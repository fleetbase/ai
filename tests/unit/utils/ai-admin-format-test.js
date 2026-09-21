import { module, test } from 'qunit';
import { formatCompact, formatDay, formatDuration, formatJson, formatNumber, formatPercent, formatRelative, statusType, stepLabel } from 'dummy/utils/ai-admin-format';

module('Unit | Utility | ai-admin-format', function () {
    test('numbers read with separators, compact notation, and percentages', function (assert) {
        assert.strictEqual(formatNumber(12400), (12400).toLocaleString());
        assert.strictEqual(formatNumber('not a number'), '0');
        assert.strictEqual(formatCompact(950), '950');
        assert.notStrictEqual(formatCompact(12400), (12400).toLocaleString());
        assert.strictEqual(formatPercent(1, 3), '33.3%');
        assert.strictEqual(formatPercent(1, 0), null);
    });

    test('durations and relative times', function (assert) {
        const start = '2026-09-20T10:00:00Z';

        assert.strictEqual(formatDuration(start, '2026-09-20T10:00:00.850Z'), '850 ms');
        assert.strictEqual(formatDuration(start, '2026-09-20T10:00:03.100Z'), '3.1 s');
        assert.strictEqual(formatDuration(start, '2026-09-20T10:02:05Z'), '2 min 5 s');
        assert.strictEqual(formatDuration(start, '2026-09-20T10:02:00Z'), '2 min');
        assert.strictEqual(formatDuration(start, null), null);
        assert.strictEqual(formatDuration('2026-09-20T11:00:00Z', start), null);

        const now = new Date('2026-09-20T12:00:00Z').getTime();
        assert.strictEqual(formatRelative('2026-09-20T11:59:40Z', now), 'just now');
        assert.strictEqual(formatRelative('2026-09-20T09:00:00Z', now), '3 hours ago');
        assert.strictEqual(formatRelative(null, now), '');
        assert.strictEqual(formatRelative('not a date', now), '');

        // Dates come from date-fns, so they never pick up a polyfilled Intl default locale.
        assert.strictEqual(formatDay('2026-09-18'), '18 Sep');
        assert.strictEqual(formatDay('2026-09-18 00:00:00'), '18 Sep');
        assert.strictEqual(formatDay(null), '');
    });

    test('step labels, JSON, and badge statuses', function (assert) {
        assert.strictEqual(stepLabel({ type: 'tool_call', input: { name: 'search_docs' } }), 'Tool: search_docs');
        assert.strictEqual(stepLabel({ type: 'temporal_context' }), 'temporal context');
        assert.strictEqual(formatJson({ a: 1 }), '{\n  "a": 1\n}');
        assert.strictEqual(formatJson('raw'), 'raw');
        assert.strictEqual(formatJson(null), '');
        assert.strictEqual(statusType('answered'), 'success');
        assert.strictEqual(statusType('apply_failed'), 'failed');
        assert.strictEqual(statusType('running'), 'info');
        assert.strictEqual(statusType('cancelled'), 'default');
    });
});
