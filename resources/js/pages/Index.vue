<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Badge, Description, EmptyStateItem, EmptyStateMenu, Header, Icon, Listing } from '@statamic/cms/ui';

const props = defineProps([
    'setupRequired', // bool — the migrations have not run
    'columns',      // Array<Column> — Statamic\CP\Column::toArray() shape
    'filters',      // Array — Scope::filters() output for the filter panel
    'hasAny',       // bool — decides empty state vs. listing
    'listingUrl',   // string — the same route, answering JSON
    'perPage',      // int
]);

const isEmpty = computed(() => !props.hasAny);
</script>

<template>
    <Head :title="__('messaging::cp.title')" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
        <template v-if="isEmpty">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="mail" class="size-5 text-gray-500" />{{ __('messaging::cp.title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="setupRequired ? __('messaging::cp.setup_required_heading') : __('messaging::cp.empty_heading')">
                <EmptyStateItem
                    icon="mail"
                    :heading="setupRequired ? __('messaging::cp.setup_required_heading') : __('messaging::cp.empty_heading')"
                    :description="setupRequired ? __('messaging::cp.setup_required_description') : __('messaging::cp.empty_description')"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('messaging::cp.title')" icon="mail" />

            <Description :text="__('messaging::cp.intro')" class="mb-4" />

            <!--
                Server mode, no action-url (nothing is done to a selection) and no
                search: the only things a search could match are texts and names,
                and the CP does not read either.
            -->
            <Listing
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :per-page="perPage"
                :allow-search="false"
                preferences-prefix="messaging"
                sort-column="created_at"
                sort-direction="desc"
                push-query
            >
                <template #cell-id="{ row }">
                    <Link :href="row.show_url" class="whitespace-nowrap">#{{ row.id }}</Link>
                </template>

                <template #cell-anonymous="{ row }">
                    {{ row.anonymous ? __('messaging::cp.yes') : __('messaging::cp.no') }}
                </template>

                <template #cell-flags="{ row }">
                    <span class="flex flex-wrap items-center gap-2">
                        <Badge v-if="row.is_flagged" pill color="red" :text="__('messaging::cp.flag_flagged')" />
                        <Badge v-if="row.is_blocked" pill color="amber" :text="__('messaging::cp.flag_blocked')" />
                        <Badge v-if="row.has_hidden" pill color="gray" :text="__('messaging::cp.flag_hidden')" />
                    </span>
                </template>
            </Listing>
        </template>
    </div>
</template>
