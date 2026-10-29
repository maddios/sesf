<script setup lang="ts">
/**
 * Extras bought on registrations (admin): change a member's quantity or delete the purchase, filtered by event.
 * Purchases are made by members, so there is no create.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminRowActions from '../../../components/admin/AdminRowActions.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import type { Extra } from '../extras/extras';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError, useAdminResource } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { BUSY_HINT, rowActions, type AdminField, type FormValues, type RowAction } from '../../../components/admin/types';
import {
  EXTRA_REGISTRATION_COLUMNS,
  EXTRA_REGISTRATION_FIELDS,
  editFields,
  extraRegistrationsApi,
  toForm,
  toInput,
  type ExtraRegistration,
} from './extraRegistrations';

const list = useAdminList<ExtraRegistration>({
  fetch: (query) => extraRegistrationsApi.list(query),
  defaults: { sort: 'created_at', direction: 'desc' },
  filterKeys: ['show_id', 'registration_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});

const editing = ref<ExtraRegistration | null>(null);
const formOpen = ref(false);
const busyId = ref<number | null>(null);

const fields = ref<AdminField[]>(EXTRA_REGISTRATION_FIELDS);
const extrasApi = useAdminResource<Extra>('extras');

async function openEdit(row: ExtraRegistration): Promise<void> {
  editing.value = row;
  fields.value = EXTRA_REGISTRATION_FIELDS;
  if (row.show_id !== null) {
    try {
      const extras = await extrasApi.list({ per_page: 100, sort: 'name', direction: 'asc', filter: { show_id: String(row.show_id) } });
      fields.value = editFields(extras.data);
    } catch (error) {
      toast.error(mapAdminError(error, 'The event’s extras could not be loaded.').message);
      return;
    }
  }
  formOpen.value = true;
}

function save(values: FormValues): Promise<ExtraRegistration> {
  return extraRegistrationsApi.update((editing.value as ExtraRegistration).id, toInput(values));
}

/** A quantity of 0 removes the purchase (204, no body: the result is undefined). */
function onSaved(result: unknown): void {
  const who = editing.value?.user_name ?? 'the member';
  toast.success(result === undefined ? `Extra purchase for ${who} removed.` : `Quantity for ${who} saved.`);
  void list.reload();
}

function onEventFilter(option: RelationOption | null): void {
  void list.setFilter('show_id', option === null ? null : String(option.id));
}

function actionsFor(row: ExtraRegistration): RowAction[] {
  const busy = busyId.value === row.id;
  return rowActions({ id: 'delete', label: 'Delete…', icon: 'trash', danger: true, disabled: busy, hint: busy ? BUSY_HINT : undefined, handler: () => remove(row) });
}

async function remove(row: ExtraRegistration): Promise<void> {
  const ok = await confirm({
    title: 'Delete extra purchase',
    message: `Remove ${row.extra_name ?? 'this extra'} from ${row.user_name ?? 'this registration'}? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = row.id;
  try {
    await extraRegistrationsApi.remove(row.id);
    toast.success('Extra purchase deleted.');
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The purchase could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}
</script>

<template>
  <div>
    <AdminPageHeader title="Extra registrations" />

    <AdminDataTable
      label="Extra registrations"
      search-label="Search extra registrations"
      :columns="EXTRA_REGISTRATION_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No extra purchases match."
      :searchable="false"
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
          <label for="extra-registration-filter-event">Event</label>
          <RelationSelect
            id="extra-registration-filter-event"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="eventId"
            placeholder="Any event"
            @change="onEventFilter"
          />
        </div>
        <div class="w-40">
          <label for="extra-registration-filter-registration">Registration ID</label>
          <input
            id="extra-registration-filter-registration"
            class="form-control w-full"
            type="search"
            inputmode="numeric"
            :value="list.state.value.filters.registration_id ?? ''"
            @change="list.setFilter('registration_id', ($event.target as HTMLInputElement).value)"
          >
        </div>
      </template>

      <template #actions="{ row }">
        <AdminRowActions
          :subject="`${row.extra_name ?? 'extra'} for ${row.user_name ?? row.id}`"
          :primary="{ id: 'edit', label: 'Edit', icon: 'edit', handler: () => openEdit(row) }"
          :actions="actionsFor(row)"
        />
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      title="Edit purchase"
      :fields="fields"
      :initial="toForm(editing)"
      :submit="save"
      submit-label="Save changes"
      size="md"
      @saved="onSaved"
    />
  </div>
</template>
