<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;

class AiConfigController extends Controller
{
    public function status(Request $request, AiProviderManager $providers)
    {
        $config = $providers->normalizeConfig(Setting::system('ai', $providers->defaultConfig()));

        return response()->json([
            'enabled' => (bool) data_get($config, 'enabled', false),
        ]);
    }

    public function show(AdminRequest $request, AiProviderManager $providers)
    {
        $config = $providers->normalizeConfig(Setting::system('ai', $providers->defaultConfig()));

        return response()->json([
            'config'   => $this->maskSecrets($config),
            'metadata' => $providers->metadata(),
        ]);
    }

    public function store(AdminRequest $request, AiProviderManager $providers)
    {
        $config   = $request->input('config', []);
        $existing = Setting::system('ai', []);
        $config   = $this->preserveMaskedSecrets($config, $existing);
        $config   = $providers->normalizeConfig($config);

        Setting::configureSystem('ai', $config);

        return response()->json(['status' => 'OK', 'config' => $this->maskSecrets($config), 'metadata' => $providers->metadata()]);
    }

    public function testProvider(AdminRequest $request, AIProviderInterface $provider)
    {
        try {
            return response()->json($provider->test($request->input('config', [])));
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ]);
        }
    }

    protected function maskSecrets(array $config): array
    {
        foreach (data_get($config, 'providers', []) as $provider => $providerConfig) {
            foreach (['api_key', 'secret', 'token'] as $key) {
                if (!empty($providerConfig[$key])) {
                    $config['providers'][$provider][$key] = '********';
                }
            }
        }

        return $config;
    }

    protected function preserveMaskedSecrets(array $config, array $existing): array
    {
        foreach (data_get($config, 'providers', []) as $provider => $providerConfig) {
            foreach (['api_key', 'secret', 'token'] as $key) {
                if (($providerConfig[$key] ?? null) === '********') {
                    $config['providers'][$provider][$key] = data_get($existing, "providers.{$provider}.{$key}");
                }
            }
        }

        return $config;
    }
}
