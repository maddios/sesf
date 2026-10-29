<script setup lang="ts">
/**
 * Show locations (admin). Venues are arranged per event like the agenda: choose an event, then reorder. Each venue
 * is edited in a modal; coordinates are not edited here.
 */
import { computed, ref } from 'vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminRowActions from '../../../components/admin/AdminRowActions.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import EventFilter from '../../../components/admin/EventFilter.vue';
import ReorderableList from '../../../components/admin/ReorderableList.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { rowActions, type FormValues, type RowAction } from '../../../components/admin/types';
import { LOCATION_FIELDS, locationsApi, saveLocationOrder, toForm, toInput, type Location } from './locations';

const NO_ROWS: ListResponse<Location> = { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } };

const list = useAdminList<Location>({
  fetch: (query) => (query.filter?.show_id === undefined ? Promise.resolve(NO_ROWS) : locationsApi.list(query)),
  defaults: { sort: 'sort_order', direction: 'asc', perPage: 100 },
  filterKeys: ['show_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});

const editing = ref<Location | null>(null);
const formOpen = ref(false);

function onEvent(value: number | null): void {
  void list.setFilter('show_id', value === null ? null : String(value));
}

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(location: Location): void {
  editing.value = location;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Location> {
  const input = toInput(values);
  if (editing.value !== null) return locationsApi.update(editing.value.id, input);
  return locationsApi.create({ ...input, show_id: eventId.value ?? undefined });
}

function onSaved(result: unknown): void {
  const location = result as Location;
  toast.success(editing.value === null ? `Location “${location.name}” created.` : `Location “${location.name}” saved.`);
  void list.reload();
}

async function reorder(ids: number[]): Promise<void> {
  if (eventId.value === null) return;
  try {
    await saveLocationOrder(eventId.value, ids);
    toast.success('Location order saved.');
  } catch (error) {
    toast.error(mapAdminError(error, 'The order could not be saved.').message);
  }
  await list.reload();
}

function actionsFor(location: Location): RowAction[] {
  return rowActions({ id: 'delete', label: 'Delete…', icon: 'trash', danger: true, handler: () => remove(location) });
}

async function remove(location: Location): Promise<void> {
  const ok = await confirm({
    title: 'Delete location',
    message: `Delete “${location.name}” permanently? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  try {
    await locationsApi.remove(location.id);
    toast.success(`Location “${location.name}” deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The location could not be deleted.').message);
  }
}
</script>

<template>
  <div class="a2-page">
    <AdminPageHeader title="Locations">
      <template #actions>
        <BaseButton
          variant="primary"
          :disabled="eventId === null"
          @click="openCreate"
        >
          New location
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
      Choose an event to see and arrange its locations.
    </p>

    <template v-else>
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
        No locations for this event yet.
      </p>

      <ReorderableList
        v-else
        :items="list.rows.value"
        :item-label="(item: Location) => item.name"
        label="Locations"
        :disabled="list.loading.value"
        @reorder="reorder"
      >
        <template #default="{ item }">
          <div class="flex min-w-0 items-center gap-2">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="font-medium text-heading">{{ item.name }}</span>
                <StatusBadge
                  v-if="item.is_primary"
                  label="Primary"
                  variant="success"
                />
              </div>
              <div class="text-sm text-muted">
                {{ item.address1 }}, {{ item.city }}, {{ item.state }} {{ item.zip }}
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
      :title="editing === null ? 'New location' : `Edit ${editing.name}`"
      :fields="LOCATION_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create location' : 'Save changes'"
      @saved="onSaved"
    />
  </div>
</template>
