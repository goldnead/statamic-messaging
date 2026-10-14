<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Card,
    CommandPaletteItem,
    ConfirmationModal,
    Description,
    Header,
    Input,
    Modal,
    Panel,
} from '@statamic/cms/ui';

const props = defineProps([
    'conversation',   // metadata only: no names, no texts
    'timeline',       // Array<{ id, sent_at, side, hidden, hidden_reason, hide_url, unhide_url }>
    'participants',   // Array<{ id, side, number, blocked, flagged, archived, unblock_url }>
    'canManage',      // bool — `manage messaging`
    'backUrl',        // string
]);

const dash = (value) => (value === null || value === undefined || value === '' ? '—' : String(value));

const sideLabel = (side, number = null) => {
    if (side === 'initiator') return __('messaging::cp.initiator');
    if (side === 'deleted') return __('messaging::cp.deleted_account');

    return number ? __('messaging::cp.recipient_numbered', { number }) : __('messaging::cp.recipient');
};

const busy = ref(false);

// Hiding asks for a reason, in core's modal.
const hiding = ref(null);
const reason = ref('');

function openHide(entry) {
    reason.value = '';
    hiding.value = entry;
}

function post(url, data = {}, done = () => {}) {
    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            done();
        },
    });
}

function hide() {
    if (!reason.value.trim()) return;

    post(hiding.value.hide_url, { reason: reason.value }, () => (hiding.value = null));
}

const unblocking = ref(null);

function unblock() {
    post(unblocking.value.unblock_url, {}, () => (unblocking.value = null));
}
</script>

<template>
    <Head :title="__('messaging::cp.conversation', { id: conversation.id })" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="__('messaging::cp.conversation', { id: conversation.id })" icon="mail">
            <CommandPaletteItem
                category="Actions"
                icon="arrow-left"
                :text="__('messaging::cp.back_to_list')"
                :url="backUrl"
            >
                <Button :href="backUrl" icon="arrow-left" :text="__('messaging::cp.back_to_list')" variant="ghost" />
            </CommandPaletteItem>
        </Header>

        <Panel :heading="__('messaging::cp.section_conversation')">
            <Card>
                <dl class="grid sm:grid-cols-[12rem_1fr] gap-x-6 gap-y-2 text-sm *:min-w-0">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.col_created') }}</dt>
                    <dd>{{ dash(conversation.created_at) }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.col_last_activity') }}</dt>
                    <dd>{{ dash(conversation.last_message_at) }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.col_recipient') }}</dt>
                    <dd>{{ conversation.recipient }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.col_anonymous') }}</dt>
                    <dd>{{ conversation.anonymous ? __('messaging::cp.yes') : __('messaging::cp.no') }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.profile_shared') }}</dt>
                    <dd>{{ conversation.profile_shared ? __('messaging::cp.yes') : __('messaging::cp.no') }}</dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.subject_type') }}</dt>
                    <dd>
                        <code v-if="conversation.subject_type" class="text-xs">{{ conversation.subject_type }}</code>
                        <template v-else>—</template>
                    </dd>

                    <dt class="text-gray-500 dark:text-gray-400">{{ __('messaging::cp.col_messages') }}</dt>
                    <dd>{{ conversation.message_count }}</dd>
                </dl>

                <div v-if="conversation.reported" class="mt-4">
                    <Badge pill color="red" :text="__('messaging::cp.reported')" />
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('messaging::cp.section_participants')">
            <Card>
                <ul class="divide-y divide-content-border">
                    <li
                        v-for="party in participants"
                        :key="party.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                    >
                        <span class="flex flex-wrap items-center gap-2 text-sm">
                            {{ sideLabel(party.side, party.number) }}
                            <Badge v-if="party.flagged" pill color="red" :text="__('messaging::cp.flag_flagged')" />
                            <Badge v-if="party.blocked" pill color="amber" :text="__('messaging::cp.flag_blocked')" />
                            <Badge v-if="party.archived" pill color="gray" :text="__('messaging::cp.archived')" />
                        </span>

                        <Button
                            v-if="canManage && party.blocked"
                            size="sm"
                            :text="__('messaging::cp.unblock')"
                            @click="unblocking = party"
                        />
                    </li>
                </ul>
            </Card>
        </Panel>

        <Panel :heading="__('messaging::cp.section_timeline')">
            <Card>
                <Description v-if="!conversation.reported && canManage" :text="__('messaging::cp.hide_requires_report')" class="mb-4" />

                <p v-if="!timeline.length" class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('messaging::cp.timeline_empty') }}
                </p>

                <ul v-else class="divide-y divide-content-border">
                    <li
                        v-for="entry in timeline"
                        :key="entry.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ __('messaging::cp.sent') }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ entry.sent_at }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ __('messaging::cp.sent_by', { side: sideLabel(entry.side) }) }}
                                </span>
                                <Badge
                                    pill
                                    :color="entry.hidden ? 'gray' : 'green'"
                                    :text="entry.hidden ? __('messaging::cp.hidden') : __('messaging::cp.visible')"
                                />
                            </div>
                            <p v-if="entry.hidden && entry.hidden_reason" class="mt-1 text-gray-500 dark:text-gray-400">
                                {{ __('messaging::cp.hidden_reason', { reason: entry.hidden_reason }) }}
                            </p>
                        </div>

                        <template v-if="canManage">
                            <Button
                                v-if="entry.hidden"
                                size="sm"
                                :text="__('messaging::cp.unhide')"
                                :loading="busy"
                                @click="post(entry.unhide_url)"
                            />
                            <Button
                                v-else-if="conversation.reported"
                                size="sm"
                                :text="__('messaging::cp.hide')"
                                @click="openHide(entry)"
                            />
                        </template>
                    </li>
                </ul>
            </Card>
        </Panel>

        <Modal
            :open="hiding !== null"
            :title="hiding ? __('messaging::cp.hide_title', { id: hiding.id }) : ''"
            @update:open="(open) => !open && (hiding = null)"
        >
            <Description :text="__('messaging::cp.hide_body')" class="mb-4" />
            <Input v-model="reason" :label="__('messaging::cp.reason')" maxlength="255" @keydown.enter.prevent="hide" />

            <template #footer>
                <div class="flex items-center justify-end space-x-3 pt-3 pb-1">
                    <Button variant="ghost" :text="__('Cancel')" @click="hiding = null" />
                    <Button variant="primary" :text="__('messaging::cp.hide')" :loading="busy" :disabled="!reason.trim()" @click="hide" />
                </div>
            </template>
        </Modal>

        <ConfirmationModal
            :open="unblocking !== null"
            :title="__('messaging::cp.unblock_title')"
            :body-text="__('messaging::cp.unblock_body')"
            :button-text="__('messaging::cp.unblock')"
            :busy="busy"
            @confirm="unblock"
            @cancel="unblocking = null"
            @update:open="(open) => !open && (unblocking = null)"
        />
    </div>
</template>
