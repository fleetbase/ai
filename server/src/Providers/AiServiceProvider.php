<?php

namespace Fleetbase\Ai\Providers;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Services\Knowledge\DocsSiteSource;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer;
use Fleetbase\Ai\Services\Knowledge\KnowledgeSearch;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Fleetbase\Ai\Support\Capabilities\FindConsoleCommandsTool;
use Fleetbase\Ai\Support\Capabilities\ProposeConsoleCommandTool;
use Fleetbase\Ai\Support\Capabilities\Query\CountRecordsTool;
use Fleetbase\Ai\Support\Capabilities\Query\GroupCountTool;
use Fleetbase\Ai\Support\Capabilities\Query\ListRecordsTool;
use Fleetbase\Ai\Support\Capabilities\ReadDocTool;
use Fleetbase\Ai\Support\Capabilities\SearchDocsTool;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Fleetbase\Ai\Support\Commands\CoreConsoleCommands;
use Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier;
use Fleetbase\Providers\CoreServiceProvider;

// @codeCoverageIgnoreStart
if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}
// @codeCoverageIgnoreEnd

/**
 * Fleetbase AI service provider.
 */
class AiServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Fleetbase\Ai\Console\Commands\SyncAiDocs::class,
        \Fleetbase\Ai\Console\Commands\ExportAiLogs::class,
        \Fleetbase\Ai\Console\Commands\EvaluateAi::class,
        \Fleetbase\Ai\Console\Commands\ReplayAiTask::class,
    ];

    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->singleton(AIProviderInterface::class, AiProviderManager::class);
        $this->app->singleton(AiCapabilityRegistry::class);
        $this->app->singleton(AiQueryRegistry::class);
        $this->app->singleton(AiCommandRegistry::class);
        $this->app->singleton(AiQueryExecutor::class);
        $this->app->singleton(AiTemporalContext::class);
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai.php', 'ai');
        $this->app->singleton(KnowledgeAudienceClassifier::class, fn () => KnowledgeAudienceClassifier::fromConfig((array) config('ai.knowledge.docs', [])));
        $this->app->singleton(DocsSiteSource::class, fn () => new DocsSiteSource((array) config('ai.knowledge.docs', [])));
        $this->app->singleton(KnowledgeIndexer::class);
        $this->app->singleton(KnowledgeSearch::class);
        $this->app->singleton(KnowledgeBootstrapper::class, fn ($app) => new KnowledgeBootstrapper($app->make(KnowledgeSearch::class), $app->make(KnowledgeIndexer::class), config('ai.knowledge.snapshot_path')));
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     *
     * @throws \Exception if the `fleetbase/core-api` package is not installed
     */
    public function boot()
    {
        $this->registerObservers();
        $this->callAfterResolving(AiCapabilityRegistry::class, function (AiCapabilityRegistry $registry) {
            $registry->register(new CurrentPageContextCapability());
            $registry->register($this->app->make(SearchDocsTool::class));
            $registry->register($this->app->make(ReadDocTool::class));
            $registry->register($this->app->make(CountRecordsTool::class));
            $registry->register($this->app->make(GroupCountTool::class));
            $registry->register($this->app->make(ListRecordsTool::class));
            $registry->register($this->app->make(FindConsoleCommandsTool::class));
            $registry->register($this->app->make(ProposeConsoleCommandTool::class));
        });
        $this->callAfterResolving(AiCommandRegistry::class, function (AiCommandRegistry $commands) {
            $commands->registerMany(CoreConsoleCommands::all());
        });
        $this->registerCommands();
        $this->scheduleCommands(function ($schedule) {
            if (config('ai.knowledge.docs.enabled', true)) {
                $schedule->command('ai:sync-docs')->weekly()->withoutOverlapping()->runInBackground();
            }
        });
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
    }
}
