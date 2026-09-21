import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import AiAdminFilters from 'dummy/utils/ai-admin-filters';

module('Integration | Component | admin/ai-usage', function (hooks) {
    setupRenderingTest(hooks);

    test('the KPI strip formats numbers, flags problems, and hides tiles an older server does not send', async function (assert) {
        this.set('summary', {
            task_count: 1284,
            total_tokens: 4200000,
            input_tokens: 3100000,
            output_tokens: 1100000,
            completed_count: 1235,
            failed_count: 12,
            session_count: 312,
            negative_feedback_count: 0,
            positive_feedback_count: 41,
            degraded_count: 5,
            truncated_count: 3,
        });
        await render(hbs`<Admin::AiUsage::KpiStrip @summary={{this.summary}} />`);

        assert.dom('[data-test-kpi="tasks"] .fleetbase-ai-usage-kpi-value').hasText((1284).toLocaleString());
        assert.dom('[data-test-kpi="success"] .fleetbase-ai-usage-kpi-value').hasText('96.2%');
        assert.dom('[data-test-kpi="failed"]').hasClass('is-danger');
        assert.dom('[data-test-kpi="feedback"]').doesNotHaveClass('is-danger');
        assert.dom('[data-test-kpi="flagged"]').hasClass('is-warning');
        assert.dom('[data-test-kpi="flagged"] .fleetbase-ai-usage-kpi-value').hasText('5 / 3');

        this.set('summary', { task_count: 0, total_tokens: 0, input_tokens: 0, output_tokens: 0, completed_count: 0, failed_count: 0 });
        assert.dom('[data-test-kpi="sessions"]').doesNotExist();
        assert.dom('[data-test-kpi="feedback"]').doesNotExist();
        assert.dom('[data-test-kpi="flagged"]').doesNotExist();
        assert.dom('[data-test-kpi="success"] .fleetbase-ai-usage-kpi-value').hasText('—');
    });

    test('a breakdown ranks rows by share of tokens and filters by the clicked row', async function (assert) {
        const drilled = [];
        this.set('tabs', [
            {
                key: 'provider',
                label: 'Provider',
                rows: [
                    { key: 'openai', label: 'openai', task_count: 3, input_tokens: 50, output_tokens: 25, total_tokens: 75 },
                    { key: 'unknown', label: 'unknown', task_count: 1, total_tokens: 25 },
                ],
            },
            { key: 'model', label: 'Model', rows: [{ key: 'gpt-5-mini', label: 'gpt-5-mini', task_count: 4, total_tokens: 100 }] },
        ]);
        this.set('onDrill', (dimension, row) => drilled.push([dimension, row.key]));

        await render(hbs`<Admin::AiUsage::Breakdown @title="What" @tabs={{this.tabs}} @totalTokens={{100}} @onDrill={{this.onDrill}} />`);

        assert.dom('[data-test-breakdown-row="openai"] .fleetbase-ai-usage-share-value').hasText('75%');
        assert.dom('[data-test-breakdown-row="openai"] .fleetbase-ai-usage-share-bar span').hasAttribute('style', 'width: 75.0%');

        await click('[data-test-breakdown-row="openai"]');
        await click('[data-test-breakdown-row="unknown"]');
        assert.deepEqual(drilled, [['provider', 'openai']], 'rows without a real key cannot be drilled into');

        await click('.fleetbase-ai-usage-tab:nth-child(2)');
        assert.dom('[data-test-breakdown-row="gpt-5-mini"] .fleetbase-ai-usage-share-value').hasText('100%');
    });

    test('the filter bar hides secondary filters behind a count and clears everything', async function (assert) {
        const changes = [];
        const filters = new AiAdminFilters();
        filters.set('feedback', 'negative');
        this.setProperties({ filters, onChange: (field) => changes.push(field) });

        await render(
            hbs`<Admin::AiAdmin::FilterBar @filters={{this.filters}} @primary={{array "session_status" "review"}} @secondary={{array "feedback" "task_status"}} @onChange={{this.onChange}} />`
        );

        assert.dom('.fleetbase-ai-admin-toolbar-count').hasText('1');
        assert.dom('.fleetbase-ai-admin-toolbar-secondary').doesNotExist();

        await click('.fleetbase-ai-admin-toolbar-more');
        assert.dom('.fleetbase-ai-admin-toolbar-secondary').exists();

        await click('.fleetbase-ai-admin-toolbar-pill');
        assert.strictEqual(filters.degraded, '1');
        assert.deepEqual(changes, ['degraded']);

        await click('.fleetbase-ai-admin-toolbar-clear');
        assert.false(filters.hasAny);
        assert.deepEqual(changes, ['degraded', 'clear']);
        assert.dom('.fleetbase-ai-admin-toolbar-clear').doesNotExist();
    });
});
