<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiSystemPrompt;

test('only a user whose type is admin is a system admin', function () {
    expect(AiAudience::forUser((object) ['uuid' => 'u-1', 'type' => 'admin'])->isSystemAdmin)->toBeTrue()
        ->and(AiAudience::forUser((object) ['uuid' => 'u-2', 'type' => 'user', 'role' => 'Administrator'])->isSystemAdmin)->toBeFalse()
        ->and(AiAudience::forUser(null)->isSystemAdmin)->toBeFalse()
        ->and(AiAudience::endUser()->toArray())->toBe(['is_system_admin' => false]);
});

test('system admin content is only allowed for system admins', function () {
    $admin = new AiAudience(true);
    $user  = new class(false) extends AiAudience {
        protected function checkPermission(string $permission): bool
        {
            return $permission === 'iam see user';
        }
    };

    expect($admin->allows(AiAudience::SYSTEM_ADMIN))->toBeTrue()
        ->and($admin->allows(AiAudience::DEVELOPER))->toBeTrue()
        ->and($admin->canAll(['anything']))->toBeTrue()
        ->and($user->allows(AiAudience::SYSTEM_ADMIN))->toBeFalse()
        ->and($user->allows(AiAudience::DEVELOPER))->toBeFalse()
        ->and($user->allows(AiAudience::END_USER))->toBeTrue()
        ->and($user->allows(null))->toBeTrue()
        ->and($user->canAll(['iam see user']))->toBeTrue()
        ->and($user->canAll(['iam see user', 'iam create user']))->toBeFalse();
});

test('end user system prompt forbids admin paths and credential setup', function () {
    $prompt = AiSystemPrompt::build(AiAudience::endUser(), ['page' => 'Fleet-Ops › Settings › Map']);

    expect($prompt)->toContain('not a Fleetbase system administrator')
        ->and($prompt)->toContain('Never direct them to the Admin area')
        ->and($prompt)->toContain('system administrator')
        ->and($prompt)->toContain('The user is currently on: Fleet-Ops › Settings › Map')
        ->and($prompt)->toContain('Never show internal route names')
        ->and($prompt)->toContain('the preview card is the source of truth')
        ->and($prompt)->not->toContain('You may explain system configuration');
});

test('system admin prompt allows system configuration guidance', function () {
    $prompt = AiSystemPrompt::build(new AiAudience(true));

    expect($prompt)->toContain('You may explain system configuration')
        ->and($prompt)->not->toContain('Never direct them to the Admin area')
        ->and($prompt)->not->toContain('The user is currently on');
});

test('grounding rule depends on whether documentation is available', function () {
    expect(AiSystemPrompt::build(AiAudience::endUser(), ['has_docs' => true]))->toContain('say you could not find it in the Fleetbase docs')
        ->and(AiSystemPrompt::build(AiAudience::endUser()))->toContain('do not invent exact menu paths');
});

test('user message delimits the request and context', function () {
    $task = new AiTask(['prompt' => '  where do I add a user?  ']);

    expect(AiSystemPrompt::userMessage($task))->toBe("<user_request>\nwhere do I add a user?\n</user_request>")
        ->and(AiSystemPrompt::userMessage($task, [['capability' => 'fleetbase.ai.temporal_context', 'data' => ['url' => 'https://x.test/a']]]))
        ->toContain("<fleetbase_context>\n")
        ->toContain('"url": "https://x.test/a"')
        ->toEndWith('</fleetbase_context>');
});

test('page names are readable and never expose route names', function () {
    expect(AiSystemPrompt::pageName('console.fleet-ops.settings.map'))->toBe('Fleet-Ops › Settings › Map')
        ->and(AiSystemPrompt::pageName('console.iam.users.index'))->toBe('IAM › Users')
        ->and(AiSystemPrompt::pageName('console.developers.api-keys.index'))->toBe('Developers › API Keys')
        ->and(AiSystemPrompt::pageName('console.ledger.settings.accounting'))->toBe('Ledger › Settings › Accounting')
        ->and(AiSystemPrompt::pageName('console.home'))->toBe('Console home')
        ->and(AiSystemPrompt::pageName(null))->toBeNull();
});
