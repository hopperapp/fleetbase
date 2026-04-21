import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const widgetService = universe.getService('widget');

        // Register in header menu
        menuService.registerHeaderMenuItem('IAM', 'console.iam', { icon: 'shield-halved', priority: 3 });

        // register metrics widget
        const widgets = [
            new Widget({
                id: 'iam-metrics-widget',
                name: 'IAM Metrics',
                description: 'IAM usage metrics.',
                icon: 'user-shield',
                component: new ExtensionComponent('@fleetbase/iam-engine', 'widget/iam-metrics'),
                grid_options: { w: 6, h: 8, minW: 6, minH: 8 },
                options: { title: 'IAM Metrics' },
            }),
        ];

        widgetService.registerWidgets('dashboard', widgets);
    },
};
