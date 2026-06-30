<?php

namespace Fleetbase\Ai\Providers;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Fleetbase\Providers\CoreServiceProvider;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}

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
        $this->app->singleton(AiQueryExecutor::class);
        $this->app->singleton(AiTemporalContext::class);
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
        });
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
    }
}
