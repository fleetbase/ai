import Service, { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';

/**
 * Runs console actions that the user confirmed in the Fleetbase AI prompt.
 *
 * The server authorizes the confirmation and returns the steps to run. A step either transitions to a
 * console route or calls a method on another engine's resource-action service (for example the IAM
 * engine's `user-actions` → `modal.create`), so dialogs open the same way they do from their own pages.
 */
export default class AiCommandsService extends Service {
    @service universe;

    /**
     * Only plain dotted method paths such as `modal.create` can be called.
     */
    static METHOD_PATH = /^[a-zA-Z][a-zA-Z0-9]*(\.[a-zA-Z][a-zA-Z0-9]*)?$/;

    get router() {
        const owner = getOwner(this);

        return owner.lookup('service:host-router') ?? owner.lookup('service:router');
    }

    async run(action) {
        for (const step of action?.steps ?? []) {
            if (step.type === 'navigate') {
                await this.navigate(step);
            } else if (step.type === 'service') {
                await this.callService(step);
            } else {
                throw new Error(`Unsupported AI action step: ${step.type}`);
            }
        }
    }

    async navigate(step) {
        const models = (step.models ?? []).filter((model) => model !== null && model !== undefined && model !== '');
        const transition = this.router.transitionTo(step.route, ...models);

        if (transition && typeof transition.then === 'function') {
            await transition;
        }
    }

    async callService(step) {
        if (!AiCommandsService.METHOD_PATH.test(step.method ?? '')) {
            throw new Error(`Refusing to call AI action method: ${step.method}`);
        }

        const engine = await this.universe.ensureEngineLoaded(step.engine);
        const actions = engine?.lookup(`service:${step.service}`);
        if (!actions) {
            throw new Error(`The ${step.service} service is not available.`);
        }

        const [first, second] = step.method.split('.');
        const target = second ? actions[first] : actions;
        const fn = second ? target?.[second] : target?.[first];

        if (typeof fn !== 'function') {
            throw new Error(`The ${step.service} service has no ${step.method} action.`);
        }

        return fn.call(target, ...(step.args ?? []));
    }
}
