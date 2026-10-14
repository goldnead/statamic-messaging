/**
 * Messaging: Statamic 6 Control Panel entry point. The Inertia identifiers must
 * match `Inertia::render('messaging::...')`; the widget name must match
 * VueComponent::render().
 */
import Index from './pages/Index.vue';
import Show from './pages/Show.vue';
import MessagingWidget from './components/MessagingWidget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('messaging::Index', Index);
    Statamic.$inertia.register('messaging::Show', Show);
    Statamic.$components.register('messaging-widget', MessagingWidget);
});
