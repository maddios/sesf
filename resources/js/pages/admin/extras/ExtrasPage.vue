<script setup lang="ts">
/**
 * Show extras (admin): paid add-ons per event with price, quantity limits and the public landing key. An extra that
 * somebody registered for cannot be deleted (409): disable it instead. Create needs an event chosen in the filter.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
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

function onTypeFilter(value: string): void {
  void list.setFilter('type_id', value === '' ? null : value);
}

function onStatusFilter(value: string): void {
  void list.setFilter('is_enabled', value === '' ? null : value);
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
  <div class="space-y-4">
    <h1 class="page-title">
      Extras
    </h1>

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
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="sm:w-64">
          <label
            for="extra-filter-event"
            class="block text-sm font-medium text-text"
          >Event</label>
          <div class="mt-1">
            <RelationSelect
              id="extra-filter-event"
              :endpoint="`${ADMIN_API_BASE}/lookups/events`"
              :model-value="eventFilterValue()"
              placeholder="Any event"
              @change="onEventFilter"
            />
          </div>
        </div>
        <div>
          <label
            for="extra-filter-type"
            class="block text-sm font-medium text-text"
          >Type</label>
          <select
            id="extra-filter-type"
            class="form-control mt-1"
            :value="list.state.value.filters.type_id ?? ''"
            @change="onTypeFilter(($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              All
            </option>
            <option
              v-for="option in EXTRA_TYPE_OPTIONS"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
        <div>
          <label
            for="extra-filter-enabled"
            class="block text-sm font-medium text-text"
          >Status</label>
          <select
            id="extra-filter-enabled"
            class="form-control mt-1"
            :value="list.state.value.filters.is_enabled ?? ''"
            @change="onStatusFilter(($event.target as HTMLSelectElement).value)"
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
      </template>

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          :disabled="eventId === null"
          :title="eventId === null ? 'Choose an event first' : undefined"
          @click="openCreate"
        >
          New extra
        </BaseButton>
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
            variant="danger"
            :loading="busyId === row.id"
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
      :title="editing === null ? 'New extra' : `Edit ${editing.name}`"
      :fields="EXTRA_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create extra' : 'Save changes'"
      @saved="onSaved"
    />
  </div>
</template>
