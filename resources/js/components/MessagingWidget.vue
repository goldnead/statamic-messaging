<script setup>
import { Badge, Widget } from '@statamic/cms/ui';

defineProps({
    title: { type: String, required: true },
    url: { type: String, required: true },
    stats: { type: Object, required: true },
});
</script>

<template>
    <Widget :title="title" icon="mail" :href="url">
        <ul class="divide-y divide-gray-200 dark:divide-gray-800">
            <li v-for="window in stats.windows" :key="window.days" class="flex flex-col gap-2 px-4 py-3">
                <span class="text-sm font-medium">{{ __('messaging::cp.widget_days', { days: window.days }) }}</span>
                <div class="flex flex-wrap gap-2">
                    <Badge pill color="blue" :text="`${__('messaging::cp.widget_conversations')}: ${window.conversations}`" />
                    <Badge pill color="gray" :text="`${__('messaging::cp.widget_messages')}: ${window.messages}`" />
                </div>
            </li>
            <li class="flex items-center justify-between gap-2 px-4 py-3">
                <span class="text-sm font-medium">{{ __('messaging::cp.widget_flagged') }}</span>
                <Badge pill :color="stats.flagged > 0 ? 'red' : 'gray'" :text="String(stats.flagged)" />
            </li>
        </ul>
    </Widget>
</template>
