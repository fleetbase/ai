import { module, test } from 'qunit';
import AiAdminFilters, { compactQuery, normalizeValue } from 'dummy/utils/ai-admin-filters';

module('Unit | Utility | ai-admin-filters', function () {
    test('changing the provider clears the model, and changing the organization clears the user', function (assert) {
        const filters = new AiAdminFilters();
        filters.set('model', 'gpt-5-mini');
        filters.setUser({ uuid: 'user-1' });

        filters.setProvider({ value: 'anthropic' });
        filters.setCompany({ uuid: 'company-1', name: 'Acme' });

        assert.strictEqual(filters.provider, 'anthropic');
        assert.strictEqual(filters.model, '');
        assert.strictEqual(filters.company_uuid, 'company-1');
        assert.strictEqual(filters.created_by_uuid, '');
        assert.strictEqual(filters.selectedUser, null);
        assert.deepEqual(filters.userQuery, { company_uuid: 'company-1' });
    });

    test('date selections accept a range, a single day, a string, or nothing', function (assert) {
        const filters = new AiAdminFilters();

        filters.setDateRange({ formattedDate: ['2026-09-01', '2026-09-20'] });
        assert.deepEqual([filters.from, filters.to], ['2026-09-01', '2026-09-20']);

        filters.setDateRange({ formattedDate: ['2026-09-05'] });
        assert.deepEqual([filters.from, filters.to], ['2026-09-05', '2026-09-05']);

        filters.setDateRange({ formattedDate: '2026-09-07' });
        assert.deepEqual([filters.from, filters.to], ['2026-09-07', '2026-09-07']);

        filters.setDateRange({});
        assert.deepEqual([filters.from, filters.to, filters.dateRange], ['', '', null]);
    });

    test('the query drops empty values and counts active filters', function (assert) {
        const filters = new AiAdminFilters();
        filters.set('search', 'dispatch');
        filters.toggle('degraded');
        filters.setCompany({ uuid: 'company-1' });

        assert.deepEqual(filters.toQuery(['search', 'degraded', 'truncated', 'company_uuid'], { page: 2 }), { search: 'dispatch', degraded: '1', company_uuid: 'company-1', page: 2 });
        assert.strictEqual(filters.activeCount(['company', 'user', 'date', 'degraded']), 2);
        assert.true(filters.hasAny);

        filters.toggle('degraded');
        filters.clear();

        assert.false(filters.hasAny);
        assert.strictEqual(filters.selectedCompany, null);
    });

    test('provider and model options come from the configured providers', function (assert) {
        const filters = new AiAdminFilters();
        const metadata = { providers: [{ label: 'OpenAI', value: 'openai', models: [{ label: 'GPT-5 mini', value: 'gpt-5-mini' }] }] };

        assert.deepEqual(
            filters.providerOptions(metadata).map((option) => option.value),
            ['', 'openai']
        );
        assert.deepEqual(filters.modelOptions(metadata), [{ label: 'Any model', value: '' }]);

        filters.setProvider('openai');
        assert.deepEqual(
            filters.modelOptions(metadata).map((option) => option.value),
            ['', 'gpt-5-mini']
        );
    });

    test('helpers normalize select values and compact queries', function (assert) {
        assert.strictEqual(normalizeValue({ value: 'a' }), 'a');
        assert.strictEqual(normalizeValue({ target: { value: 'b' } }), 'b');
        assert.strictEqual(normalizeValue(null), '');
        assert.deepEqual(compactQuery({ a: '', b: null, c: 0, d: 'x' }), { c: 0, d: 'x' });
    });
});
