<script setup lang="ts">
/**
 * Sponsors admin (reference page for the admin kit; see docs/admin-conventions.md). List with search, enabled and
 * event filters (URL-synced), logo thumbnails, and per-row edit, enable/disable, logo upload and delete. A sponsor
 * used by a show cannot be deleted (409): the page offers to disable it instead.
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminRowActions from '../../../components/admin/AdminRowActions.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import ImageUploadModal from '../../../components/admin/ImageUploadModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import SafeImage from '../../../components/ui/SafeImage.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { BUSY_HINT, rowActions, type FormValues, type RowAction } from '../../../components/admin/types';
import { SPONSOR_COLUMNS, SPONSOR_FIELDS, imageSrc, sponsorsApi, toForm, toInput, type Sponsor } from './sponsors';

const list = useAdminList<Sponsor>({
  fetch: (query) => sponsorsApi.list(query),
  defaults: { sort: 'name', direction: 'asc' },
  filterKeys: ['is_enabled', 'show_id'],
});
const toast = useToast();

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Enabled' },
  { value: '0', label: 'Disabled' },
];
const { confirm } = useConfirm();

const editing = ref<Sponsor | null>(null);
const formOpen = ref(false);
const imageFor = ref<Sponsor | null>(null);
const imageOpen = ref(false);
const busyId = ref<number | null>(null);

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(sponsor: Sponsor): void {
  editing.value = sponsor;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Sponsor> {
  const input = toInput(values);
  return editing.value === null ? sponsorsApi.create(input) : sponsorsApi.update(editing.value.id, input);
}

function onSaved(result: unknown): void {
  const sponsor = result as Sponsor;
  toast.success(editing.value === null ? `Sponsor “${sponsor.name}” created.` : `Sponsor “${sponsor.name}” saved.`);
  void list.reload();
}

async function setEnabled(sponsor: Sponsor, enabled: boolean): Promise<void> {
  busyId.value = sponsor.id;
  try {
    await sponsorsApi.action(sponsor.id, enabled ? 'enable' : 'disable');
    toast.success(`Sponsor “${sponsor.name}” ${enabled ? 'enabled' : 'disabled'}.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error).message);
  } finally {
    busyId.value = null;
  }
}

function openImage(sponsor: Sponsor): void {
  imageFor.value = sponsor;
  imageOpen.value = true;
}

function uploadImage(file: File, onProgress: (percent: number) => void): Promise<Sponsor> {
  return sponsorsApi.upload((imageFor.value as Sponsor).id, file, { onProgress });
}

function onUploaded(): void {
  toast.success(`Logo for “${imageFor.value?.name ?? 'sponsor'}” uploaded.`);
  void list.reload();
}

function actionsFor(sponsor: Sponsor): RowAction[] {
  const busy = busyId.value === sponsor.id;
  return rowActions(
    { id: 'enabled', label: sponsor.is_enabled ? 'Disable' : 'Enable', icon: sponsor.is_enabled ? 'eye-off' : 'eye', disabled: busy, hint: busy ? BUSY_HINT : undefined, handler: () => setEnabled(sponsor, !sponsor.is_enabled) },
    { id: 'image', label: 'Upload logo', icon: 'image', handler: () => openImage(sponsor) },
    { id: 'delete', label: 'Delete…', icon: 'trash', danger: true, handler: () => remove(sponsor) },
  );
}

async function remove(sponsor: Sponsor): Promise<void> {
  const ok = await confirm({
    title: 'Delete sponsor',
    message: `Delete “${sponsor.name}” permanently? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = sponsor.id;
  try {
    await sponsorsApi.remove(sponsor.id);
    toast.success(`Sponsor “${sponsor.name}” deleted.`);
    await list.reload();
  } catch (error) {
    const mapped = mapAdminError(error, 'The sponsor could not be deleted.');
    if (mapped.status === 409) {
      busyId.value = null;
      const disable = sponsor.is_enabled
        && (await confirm({ title: 'Sponsor in use', message: `${mapped.message} Disable “${sponsor.name}” now?`, confirmLabel: 'Disable sponsor' }));
      if (disable) {
        await setEnabled(sponsor, false);
      } else if (!sponsor.is_enabled) {
        toast.error(mapped.message);
      }
      return;
    }
    toast.error(mapped.message);
  } finally {
    if (busyId.value === sponsor.id) busyId.value = null;
  }
}

function onShowFilter(value: number | null): void {
  void list.setFilter('show_id', value === null ? null : String(value));
}

function showFilterValue(): number | null {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
}
</script>

<template>
  <div>
    <AdminPageHeader title="Sponsors">
      <template #actions>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          New sponsor
        </BaseButton>
      </template>
    </AdminPageHeader>

    <AdminDataTable
      label="Sponsors"
      search-label="Search sponsors"
      :columns="SPONSOR_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No sponsors match."
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
          <label for="sponsor-filter-enabled">Status</label>
          <BaseCombobox
            id="sponsor-filter-enabled"
            :model-value="list.state.value.filters.is_enabled ?? ''"
            :options="STATUS_OPTIONS"
            @update:model-value="(value) => list.setFilter('is_enabled', value === null ? '' : String(value))"
          />
        </div>
        <div class="w-64">
          <label for="sponsor-filter-show">Event</label>
          <RelationSelect
            id="sponsor-filter-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showFilterValue()"
            placeholder="Any event"
            @update:model-value="onShowFilter"
          />
        </div>
      </template>

      <template #cell-image="{ row }">
        <SafeImage
          :src="imageSrc(row)"
          :alt="`${row.name} logo`"
          :placeholder="row.has_image ? row.name : 'No logo'"
          img-class="h-10 w-10 rounded-sm border border-border object-contain bg-subtle"
          width="40"
          height="40"
        />
      </template>
      <template #cell-name="{ row }">
        <span class="font-medium text-heading">{{ row.name }}</span>
      </template>
      <template #cell-URL="{ row }">
        <a
          v-if="row.URL"
          :href="row.URL"
          target="_blank"
          rel="noopener noreferrer"
          class="break-all"
        >{{ row.URL }}</a>
        <span
          v-else
          class="text-muted"
        >—</span>
      </template>
      <template #cell-is_enabled="{ row }">
        <StatusBadge
          :label="row.is_enabled ? 'Enabled' : 'Disabled'"
          :variant="row.is_enabled ? 'success' : 'neutral'"
        />
      </template>

      <template #actions="{ row }">
        <AdminRowActions
          :subject="row.name"
          :primary="{ id: 'edit', label: 'Edit', icon: 'edit', handler: () => openEdit(row) }"
          :actions="actionsFor(row)"
        />
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New sponsor' : `Edit ${editing.name}`"
      :fields="SPONSOR_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create sponsor' : 'Save changes'"
      @saved="onSaved"
    />

    <ImageUploadModal
      v-model:open="imageOpen"
      :title="`Logo for ${imageFor?.name ?? ''}`"
      :current-src="imageFor ? imageSrc(imageFor) : null"
      :current-alt="`${imageFor?.name ?? 'Sponsor'} logo`"
      :upload="uploadImage"
      @uploaded="onUploaded"
    />
  </div>
</template>
