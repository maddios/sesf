<script setup lang="ts">
/**
 * Show extras (admin): paid add-ons per event with price, quantity limits and the public landing key. An extra that
 * somebody registered for cannot be deleted (409): disable it instead. Create needs an event chosen in the filter.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminIconButton from '../../../components/admin/AdminIconButton.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import { EXTRA_COLUMNS, EXTRA_FIELDS, EXTRA_TYPE_OPTIONS, extrasApi, toForm, toInput, type Extra } from './extras';

const list = useAdminList<Extra>({
  fetch: (query) => extrasApi.list(query),
  defaults: { sort: 'name', direction: 'asc' },
  filterKeys: ['show_id', 'type_id', 'is_enabled'],
});
const toast = useToast();
const { confirm } = useConfirm();

const TYPE_FILTER_OPTIONS = [{ value: '', label: 'All' }, ...EXTRA_TYPE_OPTIONS];
const STATUS_FILTER_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Enabled' },
  { value: '0', label: 'Disabled' },
];

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});

const editing = ref<Extra | null>(null);
const formOpen = ref(false);
const busyId = ref<number | null>(null);

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(extra: Extra): void {
  editing.value = extra;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Extra> {
  const input = toInput(values);
  if (editing.value !== null) return extrasApi.update(editing.value.id, input);
  return extrasApi.create({ ...input, show_id: eventId.value ?? undefined });
}

function onSaved(result: unknown): void {
  const extra = result as Extra;
  toast.success(editing.value === null ? `Extra “${extra.name}” created.` : `Extra “${extra.name}” saved.`);
  void list.reload();
}

function onEventFilter(option: RelationOption | null): void {
  void list.setFilter('show_id', option === null ? null : String(option.id));
}

function setSelectFilter(key: 'type_id' | 'is_enabled', value: string | number | null): void {
  void list.setFilter(key, value === null || value === '' ? null : String(value));
}

async function remove(extra: Extra): Promise<void> {
  const ok = await confirm({
    title: 'Delete extra',
    message: `Delete “${extra.name}” permanently? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = extra.id;
  try {
    await extrasApi.remove(extra.id);
    toast.success(`Extra “${extra.name}” deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The extra could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}

function eventFilterValue(): number | null {
  return eventId.value;
}
</script>

<template>
  <div>
    <AdminPageHeader title="Extras">
      <template #actions>
        <BaseButton
          variant="primary"
          :disabled="eventId === null"
          :title="eventId === null ? 'Choose an event first' : undefined"
          @click="openCreate"
        >
          New extra
        </BaseButton>
      </template>
    </AdminPageHeader>

    <AdminDataTable
      label="Extras"
      search-label="Search extras"
      :columns="EXTRA_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No extras match."
      :active-filters="list.activeFilters.value"
      @row-click="openEdit"
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="w-64">
          <label for="extra-filter-event">Event</label>
          <RelationSelect
            id="extra-filter-event"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="eventFilterValue()"
            placeholder="Any event"
            @change="onEventFilter"
          />
        </div>
        <div class="w-40">
          <label for="extra-filter-type">Type</label>
          <BaseCombobox
            id="extra-filter-type"
            :model-value="list.state.value.filters.type_id ?? ''"
            :options="TYPE_FILTER_OPTIONS"
            @update:model-value="(value) => setSelectFilter('type_id', value)"
          />
        </div>
        <div class="w-40">
          <label for="extra-filter-enabled">Status</label>
          <BaseCombobox
            id="extra-filter-enabled"
            :model-value="list.state.value.filters.is_enabled ?? ''"
            :options="STATUS_FILTER_OPTIONS"
            @update:model-value="(value) => setSelectFilter('is_enabled', value)"
          />
        </div>
      </template>

      <template #cell-name="{ row }">
        <span class="font-medium text-heading">{{ row.name }}</span>
      </template>
      <template #cell-is_enabled="{ row }">
        <StatusBadge
          :label="row.is_enabled ? 'Enabled' : 'Disabled'"
          :variant="row.is_enabled ? 'success' : 'neutral'"
        />
      </template>

      <template #actions="{ row }">
        <AdminIconButton
          icon="edit"
          :label="`Edit ${row.name}`"
          @click="openEdit(row)"
        />
        <AdminIconButton
          icon="trash"
          variant="danger"
          :label="`Delete ${row.name}`"
          :disabled="busyId === row.id"
          @click="remove(row)"
        />
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New extra' : `Edit ${editing.name}`"
      :fields="EXTRA_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create extra' : 'Save changes'"
      @saved="onSaved"
    />
  </div>
</template>
