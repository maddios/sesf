<script setup lang="ts">
/**
 * Show agenda (admin). Items are arranged per event: choose an event, then drag or use the move buttons; the new
 * order is saved for that event only. Items are edited in a modal (HTML description, cleaned by the server).
 */
import { computed, ref } from 'vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminRowActions from '../../../components/admin/AdminRowActions.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import EventFilter from '../../../components/admin/EventFilter.vue';
import ReorderableList from '../../../components/admin/ReorderableList.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { rowActions, type FormValues, type RowAction } from '../../../components/admin/types';
import { AGENDA_FIELDS, AGENDA_TYPES, agendaApi, saveAgendaOrder, toForm, toInput, type AgendaItem } from './agenda';

const NO_ROWS: ListResponse<AgendaItem> = { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } };
const TYPE_OPTIONS = [{ value: '', label: 'All' }, ...AGENDA_TYPES];

const list = useAdminList<AgendaItem>({
  fetch: (query) => (query.filter?.show_id === undefined ? Promise.resolve(NO_ROWS) : agendaApi.list(query)),
  defaults: { sort: 'sort_order', direction: 'asc', perPage: 100 },
  filterKeys: ['show_id', 'type_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});
/** The order covers every item of the event, so reordering is off while a type filter hides some. */
const typeFiltered = computed(() => (list.state.value.filters.type_id ?? '') !== '');

const editing = ref<AgendaItem | null>(null);
const formOpen = ref(false);

function onEvent(value: number | null): void {
  void list.setFilter('show_id', value === null ? null : String(value));
}

function onType(value: string | number | null): void {
  void list.setFilter('type_id', value === null ? '' : String(value));
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

function actionsFor(item: AgendaItem): RowAction[] {
  return rowActions({ id: 'delete', label: 'Delete…', icon: 'trash', danger: true, handler: () => remove(item) });
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
  <div class="a2-page">
    <AdminPageHeader title="Agenda">
      <template #actions>
        <BaseButton
          variant="primary"
          :disabled="eventId === null"
          @click="openCreate"
        >
          New agenda item
        </BaseButton>
      </template>
    </AdminPageHeader>

    <EventFilter
      :model-value="eventId"
      @update:model-value="onEvent"
    />

    <p
      v-if="eventId === null"
      class="a2-muted"
    >
      Choose an event to see and arrange its agenda.
    </p>

    <template v-else>
      <div class="a2-toolbar">
        <div class="w-48">
          <label
            for="agenda-type"
            class="block text-sm font-medium text-text"
          >Type</label>
          <BaseCombobox
            id="agenda-type"
            :model-value="list.state.value.filters.type_id ?? ''"
            :options="TYPE_OPTIONS"
            @update:model-value="onType"
          />
        </div>
      </div>

      <p
        v-if="typeFiltered"
        class="a2-small a2-muted"
      >
        Clear the type filter to change the order.
      </p>
      <p
        v-if="list.error.value"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ list.error.value }}
      </p>
      <p
        v-else-if="!list.loading.value && list.rows.value.length === 0"
        class="a2-muted"
      >
        No agenda items for this event yet.
      </p>

      <ReorderableList
        v-else
        :items="list.rows.value"
        :item-label="(item: AgendaItem) => item.name"
        label="Agenda items"
        :disabled="list.loading.value || typeFiltered"
        @reorder="reorder"
      >
        <template #default="{ item }">
          <div class="flex min-w-0 items-center gap-2">
            <div class="min-w-0 flex-1">
              <span class="font-medium text-heading">{{ item.name }}</span>
              <div class="mt-0.5 flex flex-wrap gap-1">
                <StatusBadge
                  :label="item.type"
                  variant="neutral"
                />
                <StatusBadge
                  :label="item.is_visible ? 'Visible' : 'Hidden'"
                  :variant="item.is_visible ? 'success' : 'neutral'"
                />
              </div>
            </div>
            <AdminRowActions
              :subject="item.name"
              :primary="{ id: 'edit', label: 'Edit', icon: 'edit', handler: () => openEdit(item) }"
              :actions="actionsFor(item)"
            />
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
