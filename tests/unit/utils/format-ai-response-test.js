import { module, test } from 'qunit';
import formatAiResponse from 'dummy/utils/format-ai-response';

module('Unit | Utility | format-ai-response', function () {
    test('inline code survives the emphasis pass', function (assert) {
        const html = formatAiResponse('You are on the `console.ai-config` route, switch from `ai-config` to Users.');

        assert.strictEqual(html.toString(), '<p>You are on the <code>console.ai-config</code> route, switch from <code>ai-config</code> to Users.</p>');
    });

    test('underscores inside inline code are not treated as emphasis', function (assert) {
        const html = formatAiResponse('Use `a_b_c` with _italics_ and **bold**.');

        assert.strictEqual(html.toString(), '<p>Use <code>a_b_c</code> with <em>italics</em> and <strong>bold</strong>.</p>');
    });

    test('a bare documentation URL becomes a link that opens in a new tab', function (assert) {
        const html = formatAiResponse('Docs: https://fleetbase.io/docs/platform/identity-and-access/users').toString();

        assert.strictEqual(
            html,
            '<p>Docs: <a href="https://fleetbase.io/docs/platform/identity-and-access/users" target="_blank" rel="noopener noreferrer">https://fleetbase.io/docs/platform/identity-and-access/users</a></p>'
        );
    });

    test('markdown links are kept and trailing punctuation stays outside the link', function (assert) {
        const html = formatAiResponse('See [the docs](https://fleetbase.io/docs/x) and https://fleetbase.io/docs/y.').toString();

        assert.true(html.includes('<a href="https://fleetbase.io/docs/x" target="_blank" rel="noopener noreferrer">the docs</a>'));
        assert.true(html.includes('<a href="https://fleetbase.io/docs/y" target="_blank" rel="noopener noreferrer">https://fleetbase.io/docs/y</a>.'));
    });

    test('a URL inside inline code is not linked', function (assert) {
        const html = formatAiResponse('Call `https://api.example.com/v1` directly.').toString();

        assert.strictEqual(html, '<p>Call <code>https://api.example.com/v1</code> directly.</p>');
    });

    test('underscores in an autolinked URL are not italicised', function (assert) {
        const html = formatAiResponse('Visit https://fleetbase.io/docs/a_b_c now.').toString();

        assert.true(html.includes('href="https://fleetbase.io/docs/a_b_c"'));
        assert.false(html.includes('<em>'));
    });
});
