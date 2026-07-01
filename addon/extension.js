import { MenuItem, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const registryService = universe.getService('registry');
        const menuService = universe.getService('menu');

        registryService.registerRenderableComponent('@fleetbase/console', new ExtensionComponent('@fleetbase/ai-engine', 'ai-prompt'));
        registryService.registerRenderableComponent('header-tray-items', new ExtensionComponent('@fleetbase/ai-engine', 'ai-header-tray-button'));

        menuService.registerAdminMenuPanel(
            'AI Config',
            [
                new MenuItem({
                    title: 'Provider Settings',
                    icon: 'wand-magic-sparkles',
                    component: new ExtensionComponent('@fleetbase/ai-engine', 'admin/ai-settings'),
                }),
                new MenuItem({
                    title: 'Task & Chat Logs',
                    icon: 'list-check',
                    component: new ExtensionComponent('@fleetbase/ai-engine', 'admin/ai-audit-logs'),
                }),
                new MenuItem({
                    title: 'Usage Analytics',
                    icon: 'chart-column',
                    component: new ExtensionComponent('@fleetbase/ai-engine', 'admin/ai-usage-analytics'),
                }),
            ],
            { icon: 'brain', priority: 30 }
        );
    },
};
