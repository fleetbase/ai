import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';

export default class AdminAiSettingsComponent extends Component {
    @service fetch;
    @service ai;
    @service notifications;
    @tracked config = {
        enabled: false,
        provider: 'local',
        default_model: 'fleetbase-local-preview',
        providers: {
            openai: { api_key: '', base_url: 'https://api.openai.com/v1' },
            anthropic: { api_key: '', base_url: 'https://api.anthropic.com/v1' },
        },
    };
    @tracked metadata = { providers: [] };
    @tracked testResponse = null;
    @tracked showAdvancedSettings = false;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get providerOptions() {
        return this.metadata.providers ?? [];
    }

    get isEnabled() {
        return this.config.enabled === true;
    }

    get isOpenAIProvider() {
        return this.config.provider === 'openai';
    }

    get isAnthropicProvider() {
        return this.config.provider === 'anthropic';
    }

    get hasProviderAdvancedSettings() {
        return this.isOpenAIProvider || this.isAnthropicProvider;
    }

    get selectedProviderMetadata() {
        return this.providerOptions.find((provider) => provider.value === this.config.provider);
    }

    get modelOptions() {
        return this.selectedProviderMetadata?.models ?? [];
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('config', {}, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
            this.config = this.mergeConfig(response.config ?? {});
            this.ai.applyConfig(this.config, this.metadata);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *save() {
        try {
            const response = yield this.fetch.post('config', { config: this.config }, { namespace: 'ai/int/v1' });
            this.metadata = response.metadata ?? this.metadata;
            this.config = this.mergeConfig(response.config ?? {});
            this.ai.applyConfig(this.config, this.metadata);
            this.notifications.success('AI settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *test() {
        try {
            this.testResponse = yield this.fetch.post('test-provider', { config: this.config }, { namespace: 'ai/int/v1' });
            if (this.testResponse.status === 'error') {
                return this.notifications.error(this.testResponse.message);
            }
            this.notifications.success('AI provider test completed.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    mergeConfig(config = {}) {
        return {
            ...this.config,
            ...config,
            providers: {
                ...this.config.providers,
                ...(config.providers ?? {}),
                openai: {
                    ...this.config.providers.openai,
                    ...(config.providers?.openai ?? {}),
                },
                anthropic: {
                    ...this.config.providers.anthropic,
                    ...(config.providers?.anthropic ?? {}),
                },
            },
        };
    }

    @action setValue(path, value) {
        value = this.normalizeSelectionValue(value);
        const segments = path.split('.');
        const config = JSON.parse(JSON.stringify(this.config));
        let cursor = config;
        while (segments.length > 1) {
            const segment = segments.shift();
            cursor[segment] = cursor[segment] ?? {};
            cursor = cursor[segment];
        }
        cursor[segments[0]] = value;
        this.config = config;
    }

    @action setProvider(value) {
        value = this.normalizeSelectionValue(value);
        const provider = this.providerOptions.find((option) => option.value === value);
        const config = JSON.parse(JSON.stringify(this.config));
        config.provider = value;
        config.default_model = provider?.default_model ?? config.default_model;
        this.showAdvancedSettings = false;
        this.config = config;
    }

    @action toggleAdvancedSettings() {
        this.showAdvancedSettings = !this.showAdvancedSettings;
    }

    normalizeSelectionValue(value) {
        if (value?.target) {
            return value.target.value;
        }

        return value?.value ?? value;
    }
}
