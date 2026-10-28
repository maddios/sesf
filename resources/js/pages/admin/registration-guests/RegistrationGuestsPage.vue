<script setup lang="ts">
/**
 * Guests on event registrations (admin). List with search, event and type filters (URL-synced); edit name,
 * email and type; delete. Edits and deletes re-price the registration through the registration writer.
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminIconButton from '../../../components/admin/AdminIconButton.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import {
  GUEST_COLUMNS,
  GUEST_EDIT_FIELDS,
  GUEST_TYPES,
  registrationGuestsApi,
  toForm,
  toInput,
  type RegistrationGuest,
} from './registration-guests';

const list = useAdminList<RegistrationGuest>({
  fetch: (query) => registrationGuestsApi.list(query),
  defaults: { sort: 'last', direction: 'asc' },
  filterKeys: ['show_id', 'type_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const TYPE_OPTIONS = [{ value: '', label: 'All' }, ...GUEST_TYPES];

const editing = ref<RegistrationGuest | null>(null);
const formOpen = ref(false);
const busyId = ref<number | null>(null);

function openEdit(guest: RegistrationGuest): void {
  editing.value = guest;
  formOpen.value = true;
}

function save(values: FormValues): Promise<RegistrationGuest> {
  return registrationGuestsApi.update((editing.value as RegistrationGuest).id, toInput(values));
}

function onSaved(result: unknown): void {
  const guest = result as RegistrationGuest;
  toast.success(`Guest “${guest.first} ${guest.last}” saved.`);
  void list.reload();
}

async function remove(guest: RegistrationGuest): Promise<void> {
  const ok = await confirm({
    title: 'Delete guest',
    message: `Delete guest “${guest.first} ${guest.last}” from the registration? The amount owed is recalculated.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = guest.id;
  try {
    await registrationGuestsApi.remove(guest.id);
    toast.success(`Guest “${guest.first} ${guest.last}” deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The guest could not be deleted.').message);
  } finally {
    busyId.value = null;
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
    <AdminPageHeader title="Registration guests" />

    <AdminDataTable
      label="Registration guests"
      search-label="Search guests"
      :columns="GUEST_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No guests match."
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
          <label for="guest-filter-show">Event</label>
          <RelationSelect
            id="guest-filter-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showFilterValue()"
            placeholder="Any event"
            @update:model-value="onShowFilter"
          />
        </div>
        <div class="w-40">
          <label for="guest-filter-type">Type</label>
          <BaseCombobox
            id="guest-filter-type"
            :model-value="list.state.value.filters.type_id ?? ''"
            :options="TYPE_OPTIONS"
            @update:model-value="(value) => list.setFilter('type_id', value === null ? '' : String(value))"
          />
        </div>
      </template>

      <template #actions="{ row }">
        <AdminIconButton
          icon="edit"
          :label="`Edit guest ${row.first} ${row.last}`"
          @click="openEdit(row)"
        />
        <AdminIconButton
          icon="trash"
          variant="danger"
          :disabled="busyId === row.id"
          :label="`Delete guest ${row.first} ${row.last}`"
          @click="remove(row)"
        />
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'Edit guest' : `Edit ${editing.first} ${editing.last}`"
      :fields="GUEST_EDIT_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      submit-label="Save changes"
      @saved="onSaved"
    />
  </div>
</template>
