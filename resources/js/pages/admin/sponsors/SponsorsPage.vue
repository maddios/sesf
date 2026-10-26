<script setup lang="ts">
/**
 * Sponsors admin (reference page for the admin kit; see docs/admin-conventions.md). List with search, enabled and
 * event filters (URL-synced), logo thumbnails, and per-row edit, enable/disable, logo upload and delete. A sponsor
 * used by a show cannot be deleted (409): the page offers to disable it instead.
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import ImageUploadModal from '../../../components/admin/ImageUploadModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import SafeImage from '../../../components/ui/SafeImage.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import { SPONSOR_COLUMNS, SPONSOR_FIELDS, imageSrc, sponsorsApi, toForm, toInput, type Sponsor } from './sponsors';

const list = useAdminList<Sponsor>({
  fetch: (query) => sponsorsApi.list(query),
  defaults: { sort: 'name', direction: 'asc' },
  filterKeys: ['is_enabled', 'show_id'],
});
const toast = useToast();
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
  <div class="space-y-4">
    <h1 class="page-title">
      Sponsors
    </h1>

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
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div>
          <label
            for="sponsor-filter-enabled"
            class="block text-sm font-medium text-text"
          >Status</label>
          <select
            id="sponsor-filter-enabled"
            class="form-control mt-1"
            :value="list.state.value.filters.is_enabled ?? ''"
            @change="list.setFilter('is_enabled', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              All
            </option>
            <option value="1">
              Enabled
            </option>
            <option value="0">
              Disabled
            </option>
          </select>
        </div>
        <div class="sm:w-64">
          <label
            for="sponsor-filter-show"
            class="block text-sm font-medium text-text"
          >Event</label>
          <div class="mt-1">
            <RelationSelect
              id="sponsor-filter-show"
              :endpoint="`${ADMIN_API_BASE}/lookups/events`"
              :model-value="showFilterValue()"
              placeholder="Any event"
              @update:model-value="onShowFilter"
            />
          </div>
        </div>
      </template>

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          New sponsor
        </BaseButton>
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
            :aria-label="`${row.is_enabled ? 'Disable' : 'Enable'} ${row.name}`"
            @click="setEnabled(row, !row.is_enabled)"
          >
            {{ row.is_enabled ? 'Disable' : 'Enable' }}
          </BaseButton>
          <BaseButton
            size="sm"
            :aria-label="`Upload logo for ${row.name}`"
            @click="openImage(row)"
          >
            Logo
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
