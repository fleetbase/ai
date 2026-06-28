import { MenuItem, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const registryService = universe.getService('registry');
        const menuService = universe.getService('menu');

        registryService.registerRenderableComponent('@fleetbase/console', new ExtensionComponent('@fleetbase/ai-engine', 'ai-prompt'));
        registryService.registerRenderableComponent('header-tray-items', new ExtensionComponent('@fleetbase/ai-engine', 'ai-header-tray-button'));

        menuService.registerAdminMenuPanel(
            'AI',
            [
                new MenuItem({
                    title: 'Provider Settings',
                    icon: 'wand-magic-sparkles',
                    component: new ExtensionComponent('@fleetbase/ai-engine', 'admin/ai-settings'),
                }),
            ],
            { icon: 'wand-magic-sparkles', priority: 30 }
        );
    },
};
