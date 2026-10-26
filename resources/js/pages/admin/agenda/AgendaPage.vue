<script setup lang="ts">
/**
 * Show agenda (admin). Items are arranged per event: choose an event, then drag or use the move buttons; the new
 * order is saved for that event only. Items are edited in a modal (HTML description, cleaned by the server).
 */
import { computed, ref } from 'vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import ReorderableList from '../../../components/admin/ReorderableList.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import { AGENDA_FIELDS, agendaApi, saveAgendaOrder, toForm, toInput, type AgendaItem } from './agenda';

const NO_ROWS: ListResponse<AgendaItem> = { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } };

const list = useAdminList<AgendaItem>({
  fetch: (query) => (query.filter?.show_id === undefined ? Promise.resolve(NO_ROWS) : agendaApi.list(query)),
  defaults: { sort: 'sort_order', direction: 'asc', perPage: 100 },
  filterKeys: ['show_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});
const eventLabel = ref('');

const editing = ref<AgendaItem | null>(null);
const formOpen = ref(false);

function onEvent(option: RelationOption | null): void {
  eventLabel.value = option?.label ?? '';
  void list.setFilter('show_id', option === null ? null : String(option.id));
}

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(item: AgendaItem): void {
  editing.value = item;
  formOpen.value = true;
}

function save(values: FormValues): Promise<AgendaItem> {
  const input = toInput(values);
  if (editing.value !== null) return agendaApi.update(editing.value.id, input);
  return agendaApi.create({ ...input, show_id: eventId.value ?? undefined });
}

function onSaved(result: unknown): void {
  const item = result as AgendaItem;
  toast.success(editing.value === null ? `Agenda item “${item.name}” created.` : `Agenda item “${item.name}” saved.`);
  void list.reload();
}

async function reorder(ids: number[]): Promise<void> {
  if (eventId.value === null) return;
  try {
    await saveAgendaOrder(eventId.value, ids);
    toast.success('Agenda order saved.');
  } catch (error) {
    toast.error(mapAdminError(error, 'The order could not be saved.').message);
  }
  await list.reload();
}

async function remove(item: AgendaItem): Promise<void> {
  const ok = await confirm({
    title: 'Delete agenda item',
    message: `Delete “${item.name}” permanently? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  try {
    await agendaApi.remove(item.id);
    toast.success(`Agenda item “${item.name}” deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The agenda item could not be deleted.').message);
  }
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Agenda
    </h1>

    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-64 flex-1 sm:max-w-md">
        <label
          for="agenda-event"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="agenda-event"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="eventId"
            placeholder="Choose an event"
            @change="onEvent"
          />
        </div>
      </div>
      <BaseButton
        variant="primary"
        :disabled="eventId === null"
        @click="openCreate"
      >
        New agenda item
      </BaseButton>
    </div>

    <p
      v-if="eventId === null"
      class="text-muted"
    >
      Choose an event to see and arrange its agenda.
    </p>

    <template v-else>
      <p
        v-if="list.error.value"
        role="alert"
        class="text-danger"
      >
        {{ list.error.value }}
      </p>
      <p
        v-else-if="!list.loading.value && list.rows.value.length === 0"
        class="text-muted"
      >
        No agenda items for this event yet.
      </p>

      <ReorderableList
        v-else
        :items="list.rows.value"
        :item-label="(item: AgendaItem) => item.name"
        :label="`Agenda items${eventLabel ? ` for ${eventLabel}` : ''}`"
        :disabled="list.loading.value"
        @reorder="reorder"
      >
        <template #default="{ item }">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-heading">{{ item.name }}</span>
            <StatusBadge
              :label="item.type"
              variant="neutral"
            />
            <StatusBadge
              :label="item.is_visible ? 'Visible' : 'Hidden'"
              :variant="item.is_visible ? 'success' : 'neutral'"
            />
            <div class="ml-auto flex gap-1">
              <BaseButton
                size="sm"
                :aria-label="`Edit ${item.name}`"
                @click="openEdit(item)"
              >
                Edit
              </BaseButton>
              <BaseButton
                size="sm"
                variant="danger"
                :aria-label="`Delete ${item.name}`"
                @click="remove(item)"
              >
                Delete
              </BaseButton>
            </div>
          </div>
        </template>
      </ReorderableList>
    </template>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New agenda item' : `Edit ${editing.name}`"
      :fields="AGENDA_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create item' : 'Save changes'"
      @saved="onSaved"
    />
  </div>
</template>
