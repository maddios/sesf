<script setup lang="ts">
/**
 * Show locations (admin). Venues are arranged per event like the agenda: choose an event, then reorder. Each venue
 * is edited in a modal; coordinates are not edited here.
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
const eventLabel = ref('');

const editing = ref<Location | null>(null);
const formOpen = ref(false);

function onEvent(option: RelationOption | null): void {
  eventLabel.value = option?.label ?? '';
  void list.setFilter('show_id', option === null ? null : String(option.id));
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
  <div class="space-y-4">
    <h1 class="page-title">
      Locations
    </h1>

    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-64 flex-1 sm:max-w-md">
        <label
          for="location-event"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="location-event"
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
        New location
      </BaseButton>
    </div>

    <p
      v-if="eventId === null"
      class="text-muted"
    >
      Choose an event to see and arrange its locations.
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
        No locations for this event yet.
      </p>

      <ReorderableList
        v-else
        :items="list.rows.value"
        :item-label="(item: Location) => item.name"
        :label="`Locations${eventLabel ? ` for ${eventLabel}` : ''}`"
        :disabled="list.loading.value"
        @reorder="reorder"
      >
        <template #default="{ item }">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-heading">{{ item.name }}</span>
            <span class="text-sm text-muted">{{ item.address1 }}, {{ item.city }}, {{ item.state }} {{ item.zip }}</span>
            <StatusBadge
              v-if="item.is_primary"
              label="Primary"
              variant="success"
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
      :title="editing === null ? 'New location' : `Edit ${editing.name}`"
      :fields="LOCATION_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create location' : 'Save changes'"
      @saved="onSaved"
    />
  </div>
</template>
