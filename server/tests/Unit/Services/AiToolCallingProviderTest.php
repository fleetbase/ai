<?php

use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AnthropicProvider;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Services\OpenAIProvider;
use Fleetbase\Ai\Support\AiProviderTurn;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new HttpFactory());
});

function aiToolDefinitions(): array
{
    return [
        ['name' => 'search_docs', 'description' => 'Search docs', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
        ['name' => 'read_doc', 'description' => 'Read a doc', 'parameters' => ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']], 'required' => ['reference']]],
    ];
}

function aiConversation(): array
{
    return [
        ['role' => 'user', 'content' => 'Where do I add a user?'],
        ['role' => 'assistant', 'content' => 'Let me check.', 'tool_calls' => [
            ['id' => 'call_1', 'name' => 'search_docs', 'arguments' => ['query' => 'add user']],
            ['id' => 'call_2', 'name' => 'read_doc', 'arguments' => ['reference' => 'https://fleetbase.io/docs/users']],
        ], 'raw' => null],
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'search_docs', 'content' => '{"results":[]}', 'is_error' => false],
        ['role' => 'tool', 'tool_call_id' => 'call_2', 'name' => 'read_doc', 'content' => '{"error":"not found"}', 'is_error' => true],
    ];
}

test('anthropic payload caches the system prompt and tools and adapts to the model', function () {
    $provider = new AnthropicProvider();
    $config   = ['config' => ['providers' => ['anthropic' => ['max_tokens' => 4000, 'effort' => 'medium']]]];

    $haiku = $provider->conversationPayload('claude-haiku-4-5', 'System', [['role' => 'user', 'content' => 'Hi']], aiToolDefinitions(), $config);
    $opus  = $provider->conversationPayload('claude-opus-5', 'System', [['role' => 'user', 'content' => 'Hi']], aiToolDefinitions(), $config + ['tool_choice' => 'none']);
    $plain = $provider->conversationPayload('claude-sonnet-5', 'System', [['role' => 'user', 'content' => 'Hi']], []);

    expect($haiku['max_tokens'])->toBe(4000)
        ->and($haiku['system'])->toBe([['type' => 'text', 'text' => 'System', 'cache_control' => ['type' => 'ephemeral']]])
        ->and($haiku['tools'][0])->toBe(['name' => 'search_docs', 'description' => 'Search docs', 'input_schema' => aiToolDefinitions()[0]['parameters']])
        ->and($haiku['tools'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($haiku['tool_choice'])->toBe(['type' => 'auto'])
        ->and($haiku)->not->toHaveKey('thinking')
        ->and($haiku)->not->toHaveKey('fallbacks')
        ->and($opus['thinking'])->toBe(['type' => 'adaptive'])
        ->and($opus['output_config'])->toBe(['effort' => 'medium'])
        ->and($opus['fallbacks'])->toBe('default')
        ->and($opus['tool_choice'])->toBe(['type' => 'none'])
        ->and($plain['max_tokens'])->toBe(AnthropicProvider::DEFAULT_MAX_TOKENS)
        ->and($plain['thinking'])->toBe(['type' => 'adaptive'])
        ->and($plain)->not->toHaveKeys(['tools', 'tool_choice', 'output_config']);
});

test('anthropic messages group parallel tool results, replay raw blocks, and omit empty turns', function () {
    $provider = new AnthropicProvider();
    $messages = $provider->toAnthropicMessages(array_merge(aiConversation(), [
        ['role' => 'assistant', 'content' => '', 'tool_calls' => []],
        ['role' => 'assistant', 'content' => 'ignored', 'raw' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => 'Raw answer']]],
    ]));

    expect($messages)->toHaveCount(4)
        ->and($messages[0])->toBe(['role' => 'user', 'content' => 'Where do I add a user?'])
        ->and($messages[1]['content'][0])->toBe(['type' => 'text', 'text' => 'Let me check.'])
        ->and($messages[1]['content'][1]['type'])->toBe('tool_use')
        ->and($messages[1]['content'][1]['input'])->toEqual((object) ['query' => 'add user'])
        ->and($messages[2]['role'])->toBe('user')
        ->and($messages[2]['content'])->toBe([
            ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"results":[]}'],
            ['type' => 'tool_result', 'tool_use_id' => 'call_2', 'content' => '{"error":"not found"}', 'is_error' => true],
        ])
        ->and($messages[3]['content'][0]['type'])->toBe('thinking');
});

test('anthropic responses map tool calls, truncation, and refusals', function () {
    $provider = new AnthropicProvider();

    $tools = $provider->turnFromResponse([
        'id'          => 'msg_1',
        'model'       => 'claude-opus-5',
        'stop_reason' => 'tool_use',
        'content'     => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Searching.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_docs', 'input' => ['query' => 'add user']],
        ],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 80],
    ], 'claude-opus-5');

    $truncated = $provider->turnFromResponse(['stop_reason' => 'max_tokens', 'content' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'search_docs', 'input' => []]]], 'claude-haiku-4-5');
    $refusal   = $provider->turnFromResponse(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => []], 'claude-opus-5');

    expect($tools->stopReason)->toBe(AiProviderTurn::STOP_TOOLS)
        ->and($tools->text)->toBe('Searching.')
        ->and($tools->toolCalls)->toBe([['id' => 'toolu_1', 'name' => 'search_docs', 'arguments' => ['query' => 'add user']]])
        ->and($tools->usage)->toBe(['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120, 'cache_read_input_tokens' => 80])
        ->and($tools->raw)->toHaveCount(3)
        ->and($tools->toAssistantMessage()['raw'])->toHaveCount(3)
        ->and($tools->metadata['response_id'])->toBe('msg_1')
        ->and($truncated->stopReason)->toBe(AiProviderTurn::STOP_TRUNCATED)
        ->and($truncated->hasToolCalls())->toBeFalse()
        ->and($refusal->stopReason)->toBe(AiProviderTurn::STOP_REFUSAL)
        ->and($refusal->raw)->toBeNull()
        ->and($refusal->metadata['stop_details'])->toBe(['category' => 'cyber'])
        ->and($provider->turnFromResponse(['content' => [['type' => 'text', 'text' => 'Done']]], 'm')->stopReason)->toBe(AiProviderTurn::STOP_END);
});

test('anthropic converse sends the fallback beta header only for models that use it', function () {
    Http::fake([
        'https://anthropic.test/messages' => Http::response(['model' => 'claude-opus-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Answer']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
    ]);

    $config = ['default_model' => 'claude-opus-5', 'providers' => ['anthropic' => ['api_key' => 'sk-ant', 'base_url' => 'https://anthropic.test']]];
    $turn   = (new AnthropicProvider())->converse('System', [['role' => 'user', 'content' => 'Hi']], aiToolDefinitions(), ['config' => $config]);

    Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('anthropic-beta', AnthropicProvider::SERVER_FALLBACK_BETA)
        && $request->hasHeader('x-api-key', 'sk-ant')
        && $request['fallbacks'] === 'default');

    expect($turn->text)->toBe('Answer')
        ->and($turn->provider)->toBe('anthropic')
        ->and(fn () => (new AnthropicProvider())->converse('S', [], [], ['config' => ['providers' => ['anthropic' => []]]]))->toThrow(InvalidArgumentException::class);
});

test('anthropic converse surfaces api errors', function () {
    Http::fake(['https://anthropic.test/messages' => Http::response(['error' => ['message' => 'Overloaded']], 529)]);

    $config = ['default_model' => 'claude-haiku-4-5', 'providers' => ['anthropic' => ['api_key' => 'sk-ant', 'base_url' => 'https://anthropic.test']]];

    expect(fn () => (new AnthropicProvider())->converse('System', [['role' => 'user', 'content' => 'Hi']], [], ['config' => $config]))->toThrow(RuntimeException::class, 'Overloaded');

    Http::assertSent(fn (HttpRequest $request) => !$request->hasHeader('anthropic-beta'));
});

test('openai payload is stateless and carries encrypted reasoning for reasoning models', function () {
    $provider = new OpenAIProvider();
    $options  = ['config' => ['providers' => ['openai' => ['max_output_tokens' => 3000, 'reasoning_effort' => 'low']]], 'tool_choice' => 'none'];

    $reasoning = $provider->conversationPayload('gpt-5.4-mini', 'System', [['role' => 'user', 'content' => 'Hi']], aiToolDefinitions(), $options);
    $plain     = $provider->conversationPayload('custom-model', 'System', [['role' => 'user', 'content' => 'Hi']], []);

    expect($reasoning['instructions'])->toBe('System')
        ->and($reasoning['store'])->toBeFalse()
        ->and($reasoning['max_output_tokens'])->toBe(3000)
        ->and($reasoning['include'])->toBe(['reasoning.encrypted_content'])
        ->and($reasoning['reasoning'])->toBe(['effort' => 'low'])
        ->and($reasoning['tools'][0])->toBe(['type' => 'function', 'name' => 'search_docs', 'description' => 'Search docs', 'parameters' => aiToolDefinitions()[0]['parameters'], 'strict' => false])
        ->and($reasoning['tool_choice'])->toBe('none')
        ->and($plain['max_output_tokens'])->toBe(OpenAIProvider::DEFAULT_MAX_OUTPUT_TOKENS)
        ->and($plain)->not->toHaveKeys(['include', 'reasoning', 'tools', 'tool_choice']);
});

test('openai input replays reasoning items, assistant text, function calls, and outputs', function () {
    $conversation              = aiConversation();
    $conversation[1]['raw']    = [['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc'], ['type' => 'message', 'id' => 'msg_1']];
    $input                     = (new OpenAIProvider())->toResponsesInput(array_merge($conversation, [['role' => 'assistant', 'content' => '  ']]));

    expect($input)->toBe([
        ['role' => 'user', 'content' => 'Where do I add a user?'],
        ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc'],
        ['role' => 'assistant', 'content' => 'Let me check.'],
        ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'search_docs', 'arguments' => '{"query":"add user"}'],
        ['type' => 'function_call', 'call_id' => 'call_2', 'name' => 'read_doc', 'arguments' => '{"reference":"https://fleetbase.io/docs/users"}'],
        ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"results":[]}'],
        ['type' => 'function_call_output', 'call_id' => 'call_2', 'output' => '{"error":"not found"}'],
    ]);
});

test('openai responses map function calls, incomplete output, and refusals', function () {
    $provider = new OpenAIProvider();

    $tools = $provider->turnFromResponse([
        'id'     => 'resp_1',
        'status' => 'completed',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Checking.']]],
            ['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'search_docs', 'arguments' => '{"query":"add user"}'],
            ['type' => 'function_call', 'call_id' => 'call_10', 'name' => 'read_doc', 'arguments' => 'not json'],
        ],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 10, 'total_tokens' => 60, 'input_tokens_details' => ['cached_tokens' => 40]],
    ], 'gpt-5.4');

    $incomplete = $provider->turnFromResponse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [['type' => 'function_call', 'call_id' => 'c', 'name' => 'x', 'arguments' => '{}']]], 'gpt-5.4');
    $filtered   = $provider->turnFromResponse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter'], 'output' => []], 'gpt-5.4');
    $refused    = $provider->turnFromResponse(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]]], 'gpt-5.4');

    expect($tools->stopReason)->toBe(AiProviderTurn::STOP_TOOLS)
        ->and($tools->text)->toBe('Checking.')
        ->and($tools->toolCalls)->toBe([
            ['id' => 'call_9', 'name' => 'search_docs', 'arguments' => ['query' => 'add user']],
            ['id' => 'call_10', 'name' => 'read_doc', 'arguments' => []],
        ])
        ->and($tools->raw)->toBe([['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc']])
        ->and($tools->usage['cache_read_input_tokens'])->toBe(40)
        ->and($incomplete->stopReason)->toBe(AiProviderTurn::STOP_TRUNCATED)
        ->and($incomplete->hasToolCalls())->toBeFalse()
        ->and($filtered->stopReason)->toBe(AiProviderTurn::STOP_REFUSAL)
        ->and($refused->stopReason)->toBe(AiProviderTurn::STOP_REFUSAL)
        ->and($provider->turnFromResponse(['status' => 'completed', 'output' => []], 'gpt')->stopReason)->toBe(AiProviderTurn::STOP_END);
});

test('openai converse posts to the responses endpoint and surfaces errors', function () {
    Http::fake([
        'https://openai.test/responses' => Http::sequence()
            ->push(['status' => 'completed', 'model' => 'gpt-5.4', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi there']]]]])
            ->push(['error' => ['message' => 'Rate limited']], 429),
    ]);

    $config   = ['default_model' => 'gpt-5.4', 'providers' => ['openai' => ['api_key' => 'sk-test', 'base_url' => 'https://openai.test']]];
    $provider = new OpenAIProvider();

    expect($provider->converse('System', [['role' => 'user', 'content' => 'Hi']], [], ['config' => $config])->text)->toBe('Hi there')
        ->and(fn () => $provider->converse('System', [['role' => 'user', 'content' => 'Hi']], [], ['config' => $config]))->toThrow(RuntimeException::class, 'Rate limited')
        ->and(fn () => $provider->converse('System', [], [], ['config' => ['providers' => ['openai' => []]]]))->toThrow(InvalidArgumentException::class)
        ->and($provider->supportsTools())->toBeTrue();

    Http::assertSent(fn (HttpRequest $request) => $request['store'] === false && $request->hasHeader('Authorization', 'Bearer sk-test'));
});

test('provider manager uses tool calling only for enabled live providers', function () {
    Http::fake([
        'https://anthropic.test/messages' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Managed']]]),
    ]);

    $manager = new AiProviderManager(new LocalAIProvider(), new OpenAIProvider(), new AnthropicProvider());
    $config  = ['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5', 'providers' => ['anthropic' => ['api_key' => 'sk-ant', 'base_url' => 'https://anthropic.test']]];

    expect($manager->supportsTools($config))->toBeTrue()
        ->and($manager->supportsTools(['enabled' => true, 'provider' => 'openai']))->toBeTrue()
        ->and($manager->supportsTools(array_merge($config, ['tool_calling' => false])))->toBeFalse()
        ->and($manager->normalizeConfig(array_merge($config, ['tool_calling' => false]))['tool_calling'])->toBeFalse()
        ->and($manager->normalizeConfig($config)['tool_calling'])->toBeTrue()
        ->and($manager->supportsTools(array_merge($config, ['enabled' => false])))->toBeFalse()
        ->and($manager->supportsTools(['enabled' => true, 'provider' => 'local']))->toBeFalse()
        ->and($manager->converse('System', [['role' => 'user', 'content' => 'Hi']], [], ['config' => $config])->text)->toBe('Managed')
        ->and(fn () => $manager->converse('System', [], [], ['config' => ['enabled' => false]]))->toThrow(RuntimeException::class, 'does not support tool calling')
        ->and(collect($manager->metadata()['providers'])->firstWhere('value', 'anthropic')['models'])->toContain(['label' => 'Claude Opus 5', 'value' => 'claude-opus-5'], ['label' => 'Claude Sonnet 5', 'value' => 'claude-sonnet-5'], ['label' => 'Claude Fable 5.1', 'value' => 'claude-fable-5-1']);
});
