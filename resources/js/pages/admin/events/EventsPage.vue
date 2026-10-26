<script setup lang="ts">
/**
 * Events admin (car_show). List with search and status filter (URL-synced), create with an optional clone of the
 * car bodies and judging setup from an earlier event, edit, activate/deactivate, per-event sponsors (dialog), and
 * delete. An event with registrations, sponsors or setup is a 409: the page says to deactivate it instead.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import EventSponsorsDialog from './EventSponsorsDialog.vue';
import {
  EVENT_COLUMNS,
  EVENT_FIELDS,
  cloneField,
  eventsApi,
  fetchCloneSources,
  toCreateInput,
  toForm,
  toInput,
  type CloneSource,
  type Event,
} from './events';

const list = useAdminList<Event>({
  fetch: (query) => eventsApi.list(query),
  defaults: { sort: 'date_start', direction: 'desc' },
  filterKeys: ['is_active'],
});
const toast = useToast();
const { confirm } = useConfirm();

const editing = ref<Event | null>(null);
const formOpen = ref(false);
const cloneSources = ref<CloneSource[]>([]);
const sponsorsFor = ref<Event | null>(null);
const sponsorsOpen = ref(false);
const busyId = ref<number | null>(null);

const formFields = computed(() => (editing.value === null ? [cloneField(cloneSources.value), ...EVENT_FIELDS] : EVENT_FIELDS));

async function openCreate(): Promise<void> {
  editing.value = null;
  try {
    cloneSources.value = await fetchCloneSources();
  } catch (error) {
    toast.error(mapAdminError(error, 'The earlier events could not be loaded.').message);
  }
  formOpen.value = true;
}

function openEdit(event: Event): void {
  editing.value = event;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Event> {
  return editing.value === null ? eventsApi.create(toCreateInput(values)) : eventsApi.update(editing.value.id, toInput(values));
}

function onSaved(result: unknown): void {
  const event = result as Event;
  toast.success(editing.value === null ? `Event “${event.name}” created.` : `Event “${event.name}” saved.`);
  void list.reload();
}

async function setActive(event: Event, active: boolean): Promise<void> {
  busyId.value = event.id;
  try {
    await eventsApi.action(event.id, active ? 'activate' : 'deactivate');
    toast.success(`Event “${event.name}” ${active ? 'activated' : 'deactivated'}.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error).message);
  } finally {
    busyId.value = null;
  }
}

function openSponsors(event: Event): void {
  sponsorsFor.value = event;
  sponsorsOpen.value = true;
}

async function remove(event: Event): Promise<void> {
  const ok = await confirm({
    title: 'Delete event',
    message: `Delete “${event.name}” permanently? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = event.id;
  try {
    await eventsApi.remove(event.id);
    toast.success(`Event “${event.name}” deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The event could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Events
    </h1>

    <AdminDataTable
      label="Events"
      search-label="Search events"
      :columns="EVENT_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No events match."
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div>
          <label
            for="event-filter-active"
            class="block text-sm font-medium text-text"
          >Status</label>
          <select
            id="event-filter-active"
            class="form-control mt-1"
            :value="list.state.value.filters.is_active ?? ''"
            @change="list.setFilter('is_active', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              All
            </option>
            <option value="1">
              Active
            </option>
            <option value="0">
              Inactive
            </option>
          </select>
        </div>
      </template>

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          New event
        </BaseButton>
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium text-heading">{{ row.name }}</span>
      </template>
      <template #cell-date_start="{ row }">
        {{ row.date_start ?? '—' }}
      </template>
      <template #cell-is_active="{ row }">
        <StatusBadge
          :label="row.is_active ? 'Active' : 'Inactive'"
          :variant="row.is_active ? 'success' : 'neutral'"
        />
      </template>

      <template #actions="{ row }">
        <div class="inline-flex flex-wrap justify-end gap-1">
          <BaseButton
            size="sm"
            :aria-label="`Edit ${row.name}`"
            @click="openEdit(row)"
          >
            Edit
          </BaseButton>
          <BaseButton
            size="sm"
            :loading="busyId === row.id"
            :aria-label="`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`"
            @click="setActive(row, !row.is_active)"
          >
            {{ row.is_active ? 'Deactivate' : 'Activate' }}
          </BaseButton>
          <BaseButton
            size="sm"
            :aria-label="`Sponsors for ${row.name}`"
            @click="openSponsors(row)"
          >
            Sponsors
          </BaseButton>
          <BaseButton
            size="sm"
            variant="danger"
            :aria-label="`Delete ${row.name}`"
            @click="remove(row)"
          >
            Delete
          </BaseButton>
        </div>
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New event' : `Edit ${editing.name}`"
      :fields="formFields"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create event' : 'Save changes'"
      @saved="onSaved"
    />

    <EventSponsorsDialog
      v-model:open="sponsorsOpen"
      :event="sponsorsFor"
    />
  </div>
</template>
