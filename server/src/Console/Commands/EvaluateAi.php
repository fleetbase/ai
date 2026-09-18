<?php

namespace Fleetbase\Ai\Console\Commands;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\Eval\AiEvalGrader;
use Fleetbase\Ai\Support\Eval\AiEvalRunner;
use Fleetbase\Models\Setting;
use Illuminate\Console\Command;

class EvaluateAi extends Command
{
    protected $signature = 'ai:eval
        {file? : Golden cases JSON (defaults to the packaged cases)}
        {--case=* : Only run these case ids}
        {--company= : Company uuid whose data and permissions scope the tools}
        {--provider= : Override the configured provider (openai or anthropic)}
        {--model= : Override the configured model}
        {--json= : Also write the results to this file}
        {--yes : Skip the confirmation (runs call the real provider and cost money)}';

    protected $description = 'Run golden cases against the configured AI provider and report the pass rate';

    public function handle(AIProviderInterface $provider, AiCapabilityRegistry $registry, AiTemporalContext $temporalContext): int
    {
        $config = $this->config();

        if (!method_exists($provider, 'supportsTools') || !$provider->supportsTools($config)) {
            $this->error('Evaluations need an enabled OpenAI or Claude provider with tool calling.');

            return self::FAILURE;
        }

        try {
            $cases = AiEvalRunner::loadCases($this->argument('file') ?: static::defaultCasesPath(), (array) $this->option('case'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!$this->option('yes') && !$this->confirm(sprintf('Run %d cases against %s (%s)? This calls the provider and is billed.', count($cases), $config['provider'], $config['default_model']))) {
            return self::FAILURE;
        }

        if ($company = $this->option('company')) {
            session(['company' => $company]);
        }

        $runner  = new AiEvalRunner($provider, $registry, $temporalContext, new AiEvalGrader());
        $results = [];

        foreach ($cases as $case) {
            $result    = $runner->run($case, $config);
            $results[] = $result;

            $this->line(sprintf('%s %s', $result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>', $case['id']));
            foreach ($result['failures'] as $failure) {
                $this->line("     - {$failure}");
            }
        }

        $passed = count(array_filter($results, fn ($result) => $result['passed']));
        $tokens = array_sum(array_map(fn ($result) => (int) ($result['usage']['input_tokens'] ?? 0) + (int) ($result['usage']['output_tokens'] ?? 0), $results));

        $this->newLine();
        $this->info(sprintf('%d/%d cases passed (%.0f%%) with %s %s, %d tokens.', $passed, count($results), count($results) ? $passed / count($results) * 100 : 0, $config['provider'], $config['default_model'], $tokens));

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode(['provider' => $config['provider'], 'model' => $config['default_model'], 'passed' => $passed, 'total' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $passed === count($results) ? self::SUCCESS : self::FAILURE;
    }

    public static function defaultCasesPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/ai-eval/cases.json';
    }

    protected function config(): array
    {
        $config = $this->systemConfig();

        return array_merge($config, array_filter([
            'enabled'       => true,
            'provider'      => $this->option('provider') ?: ($config['provider'] ?? null),
            'default_model' => $this->option('model') ?: ($config['default_model'] ?? null),
        ]));
    }

    /**
     * @codeCoverageIgnore
     */
    protected function systemConfig(): array
    {
        return Setting::system('ai', []);
    }
}
