<script setup lang="ts">
/**
 * Events admin (car_show). List with search and status filter (URL-synced), create with an optional clone of the
 * car bodies and judging setup from an earlier event, edit, activate/deactivate, release/withhold judging results,
 * image and schedule PDF uploads, per-event sponsors (dialog), and delete. An event with registrations, sponsors or setup is a 409: the page says to deactivate it instead.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminIconButton from '../../../components/admin/AdminIconButton.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import ImageUploadModal from '../../../components/admin/ImageUploadModal.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
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
  judgingResultsApi,
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

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Active' },
  { value: '0', label: 'Inactive' },
];

const editing = ref<Event | null>(null);
const formOpen = ref(false);
const cloneSources = ref<CloneSource[]>([]);
const sponsorsFor = ref<Event | null>(null);
const sponsorsOpen = ref(false);
const busyId = ref<number | null>(null);
const imageFor = ref<Event | null>(null);
const imageOpen = ref(false);
const pdfFor = ref<Event | null>(null);
const pdfInput = ref<HTMLInputElement | null>(null);

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

async function setJudgingReleased(event: Event, released: boolean): Promise<void> {
  busyId.value = event.id;
  try {
    await judgingResultsApi.setReleased(event.id, released);
    toast.success(`Judging results for “${event.name}” ${released ? 'released' : 'withheld'}.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error).message);
  } finally {
    busyId.value = null;
  }
}

async function sendResults(event: Event): Promise<void> {
  const ok = await confirm({
    title: 'Send judging results',
    message: `Email every owner of a judged car in “${event.name}” a link to their results?`,
    confirmLabel: 'Send results',
  });
  if (!ok) return;
  busyId.value = event.id;
  try {
    const { data } = await judgingResultsApi.mail(event.id);
    toast.success(`Judging results for “${event.name}” sent (${data.sent}).`);
  } catch (error) {
    toast.error(mapAdminError(error, 'The judging results could not be sent.').message);
  } finally {
    busyId.value = null;
  }
}

function openImage(event: Event): void {
  imageFor.value = event;
  imageOpen.value = true;
}

function uploadImage(file: File, onProgress: (percent: number) => void): Promise<Event> {
  return eventsApi.upload((imageFor.value as Event).id, file, { onProgress });
}

function onImageUploaded(): void {
  toast.success(`Image for “${imageFor.value?.name ?? 'the event'}” uploaded.`);
  void list.reload();
}

function choosePdf(event: Event): void {
  pdfFor.value = event;
  pdfInput.value?.click();
}

async function onPdfChosen(change: globalThis.Event): Promise<void> {
  const input = change.target as HTMLInputElement;
  const file = input.files?.[0];
  const event = pdfFor.value;
  input.value = '';
  if (file === undefined || event === null) return;
  busyId.value = event.id;
  try {
    await eventsApi.upload(event.id, file, { path: 'schedule-pdf' });
    toast.success(`Schedule PDF for “${event.name}” uploaded.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The schedule PDF could not be uploaded.').message);
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
  <div>
    <AdminPageHeader title="Events">
      <template #actions>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          New event
        </BaseButton>
      </template>
    </AdminPageHeader>

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
      :active-filters="list.activeFilters.value"
      @row-click="openEdit"
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="w-40">
          <label for="event-filter-active">Status</label>
          <BaseCombobox
            id="event-filter-active"
            :model-value="list.state.value.filters.is_active ?? ''"
            :options="STATUS_OPTIONS"
            @update:model-value="(value) => list.setFilter('is_active', value === null ? '' : String(value))"
          />
        </div>
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
        <AdminIconButton
          icon="edit"
          :label="`Edit ${row.name}`"
          @click="openEdit(row)"
        />
        <AdminIconButton
          icon="power"
          :label="`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`"
          :disabled="busyId === row.id"
          @click="setActive(row, !row.is_active)"
        />
        <AdminIconButton
          icon="check"
          :label="`${row.is_judging_released ? 'Withhold' : 'Release'} judging results for ${row.name}`"
          :disabled="busyId === row.id"
          @click="setJudgingReleased(row, !row.is_judging_released)"
        />
        <AdminIconButton
          v-if="row.is_judging_released"
          icon="mail"
          :label="`Send judging results for ${row.name}`"
          :disabled="busyId === row.id"
          @click="sendResults(row)"
        />
        <AdminIconButton
          icon="image"
          :label="`Upload image for ${row.name}`"
          @click="openImage(row)"
        />
        <AdminIconButton
          icon="calendar"
          :label="`Upload schedule PDF for ${row.name}`"
          @click="choosePdf(row)"
        />
        <BaseButton
          size="sm"
          :to="`/admin/expenses?show_id=${row.id}`"
          :aria-label="`Expenses for ${row.name}`"
        >
          Expenses
        </BaseButton>
        <AdminIconButton
          icon="users"
          :label="`Sponsors for ${row.name}`"
          @click="openSponsors(row)"
        />
        <AdminIconButton
          icon="trash"
          variant="danger"
          :label="`Delete ${row.name}`"
          @click="remove(row)"
        />
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

    <ImageUploadModal
      v-model:open="imageOpen"
      :title="imageFor === null ? 'Event image' : `Image for ${imageFor.name}`"
      :current-src="imageFor?.has_image ? imageFor.image_url : null"
      :upload="uploadImage"
      @uploaded="onImageUploaded"
    />

    <input
      ref="pdfInput"
      type="file"
      class="sr-only"
      accept="application/pdf"
      aria-label="Schedule PDF file"
      tabindex="-1"
      @change="onPdfChosen"
    >

    <EventSponsorsDialog
      v-model:open="sponsorsOpen"
      :event="sponsorsFor"
    />
  </div>
</template>
