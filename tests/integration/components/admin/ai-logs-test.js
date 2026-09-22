import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, triggerKeyEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class FetchStub extends Service {
    requests = [];

    async get(path) {
        this.requests.push(path);

        return {
            task: {
                steps: [
                    {
                        uuid: 'step-1',
                        type: 'tool_call',
                        status: 'completed',
                        input: { name: 'search_docs', arguments: { query: 'users' } },
                        output: { results: 3 },
                        usage: { total_tokens: 40 },
                    },
                    { uuid: 'step-2', type: 'provider_call', status: 'failed', error: { message: 'timeout' } },
                ],
            },
        };
    }
}

class NotificationsStub extends Service {
    messages = [];

    success(message) {
        this.messages.push(message);
    }

    serverError(error) {
        this.messages.push(error);
    }
}

const TASK = {
    uuid: 'task-1',
    status: 'answered',
    provider: 'openai',
    model: 'gpt-5-mini',
    prompt: 'How do I create a user?',
    response: 'Go to **IAM › Users** and click **New**.',
    input_tokens: 1000,
    output_tokens: 204,
    total_tokens: 1204,
    started_at: '2026-09-20T10:00:00Z',
    completed_at: '2026-09-20T10:00:03.100Z',
    feedback_rating: -1,
    feedback_comment: 'Wrong menu',
    steps_count: 2,
    metadata: { mode: 'tool_calling', tool_calls: 2, degraded: true, truncated: true, unreachable_capabilities: ['fleet-ops.import_orders_preview'] },
};

module('Integration | Component | admin/ai-logs', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:notifications', NotificationsStub);
    });

    test('a turn shows the full prompt, the rendered answer, and why it needs review', async function (assert) {
        this.set('task', TASK);
        await render(hbs`<Admin::AiLogs::Turn @task={{this.task}} />`);

        assert.dom('.fleetbase-ai-logs-prompt').hasText('How do I create a user?');
        assert.dom('.fleetbase-ai-logs-answer strong').hasText('IAM › Users');
        assert.dom('.fleetbase-ai-logs-flags').includesText('Not helpful');
        assert.dom('.fleetbase-ai-logs-flags').includesText('Degraded');
        assert.dom('.fleetbase-ai-logs-flags').includesText('Cut off');
        assert.dom('.fleetbase-ai-logs-callout.is-danger').includesText('Wrong menu');
        assert.dom('.fleetbase-ai-logs-callout.is-warning').includesText('fleet-ops.import_orders_preview');
        assert.dom(this.element).includesText('3.1 s');
        assert.dom(this.element).includesText('Tool calling · 2 tool calls');
    });

    test('steps load once when first opened and each step expands to its payload', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        this.set('task', TASK);
        await render(hbs`<Admin::AiLogs::Turn @task={{this.task}} />`);

        assert.dom('.fleetbase-ai-logs-steps-toggle').includesText('2 steps');
        assert.dom('.fleetbase-ai-logs-step').doesNotExist();

        await click('.fleetbase-ai-logs-steps-toggle');
        assert.deepEqual(fetch.requests, ['admin/tasks/task-1']);
        assert.dom('.fleetbase-ai-logs-step').exists({ count: 2 });
        assert.dom('[data-test-step="step-1"] .fleetbase-ai-logs-step-name').hasText('Tool: search_docs');

        await click('[data-test-step="step-1"] .fleetbase-ai-logs-step-summary');
        assert.dom('[data-test-step="step-1"] .fleetbase-ai-logs-json').exists({ count: 2 });

        await click('[data-test-step="step-2"] .fleetbase-ai-logs-step-summary');
        assert.dom('[data-test-step="step-1"] .fleetbase-ai-logs-json').doesNotExist();
        assert.dom('[data-test-step="step-2"] .fleetbase-ai-logs-json.is-error').includesText('timeout');

        await click('.fleetbase-ai-logs-steps-toggle');
        await click('.fleetbase-ai-logs-steps-toggle');
        assert.deepEqual(fetch.requests, ['admin/tasks/task-1'], 'reopening does not fetch again');
    });

    test('the pane invites a selection, then shows the conversation header and every turn', async function (assert) {
        this.set('session', null);
        await render(hbs`<Admin::AiLogs::SessionPane @session={{this.session}} />`);
        assert.dom('.fleetbase-ai-logs-empty').includesText('Select a conversation to read it');

        this.set('session', {
            uuid: 'session-1',
            title: 'Creating users',
            status: 'active',
            tasks_count: 2,
            total_tokens: 12400,
            company: { name: 'Acme' },
            created_by: { name: 'Jane', email: 'jane@example.test' },
            tasks: [TASK, { ...TASK, uuid: 'task-2', feedback_rating: 1, feedback_comment: null, metadata: {} }],
        });

        assert.dom('.fleetbase-ai-logs-pane-title').hasText('Creating users');
        assert.dom('.fleetbase-ai-logs-pane-meta').includesText('Acme · Jane');
        assert.dom('.fleetbase-ai-logs-pane-meta').includesText(`${(12400).toLocaleString()} tokens`);
        assert.dom('.fleetbase-ai-logs-pane-meta').includesText('2 turns');
        assert.dom('.fleetbase-ai-logs-turn').exists({ count: 2 });
    });

    test('the list marks the selection, moves it with the keyboard, and offers more rows', async function (assert) {
        const selected = [];
        this.set('sessions', [
            {
                uuid: 's1',
                title: 'First',
                status: 'active',
                tasks_count: 3,
                total_tokens: 12400,
                negative_feedback_count: 1,
                flagged_count: 2,
                company: { name: 'Acme' },
                created_by: { name: 'Jane' },
            },
            { uuid: 's2', title: '', status: 'ended', tasks_count: 1, total_tokens: 90 },
        ]);
        this.set('selectedId', 's1');
        this.set('hasMore', false);
        this.set('onSelect', (session) => {
            selected.push(session.uuid);
            this.set('selectedId', session.uuid);
        });

        await render(hbs`<Admin::AiLogs::SessionList @sessions={{this.sessions}} @selectedId={{this.selectedId}} @hasMore={{this.hasMore}} @onSelect={{this.onSelect}} />`);

        assert.dom('[data-test-session-row="s1"]').hasClass('is-selected');
        assert.dom('[data-test-session-row="s1"] .fleetbase-ai-logs-signal.is-danger').includesText('1');
        assert.dom('[data-test-session-row="s1"] .fleetbase-ai-logs-signal.is-warning').includesText('2');
        assert.dom('[data-test-session-row="s2"] .fleetbase-ai-logs-row-title').hasText('New AI chat');
        assert.dom('.fleetbase-ai-logs-list-footer').doesNotExist();

        await triggerKeyEvent('[role="listbox"]', 'keydown', 'ArrowDown');
        assert.deepEqual(selected, ['s2']);
        assert.dom('[data-test-session-row="s2"]').hasClass('is-selected');

        await triggerKeyEvent('[role="listbox"]', 'keydown', 'ArrowDown');
        assert.deepEqual(selected, ['s2'], 'stays on the last row');

        await click('[data-test-session-row="s1"]');
        assert.deepEqual(selected, ['s2', 's1']);

        this.set('hasMore', true);
        assert.dom('.fleetbase-ai-logs-list-footer').exists();
    });

    test('an empty list explains whether filters hide the conversations', async function (assert) {
        this.set('filtered', true);
        await render(hbs`<Admin::AiLogs::SessionList @sessions={{(array)}} @isFiltered={{this.filtered}} />`);
        assert.dom('.fleetbase-ai-logs-empty').includesText('Try removing a filter.');
    });
});
