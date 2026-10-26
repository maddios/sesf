<script setup lang="ts">
/**
 * Extras bought on registrations (admin): change a member's quantity or delete the purchase, filtered by event.
 * Purchases are made by members, so there is no create.
 */
import { computed, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import {
  EXTRA_REGISTRATION_COLUMNS,
  EXTRA_REGISTRATION_FIELDS,
  extraRegistrationsApi,
  toForm,
  toInput,
  type ExtraRegistration,
} from './extraRegistrations';

const list = useAdminList<ExtraRegistration>({
  fetch: (query) => extraRegistrationsApi.list(query),
  defaults: { sort: 'created_at', direction: 'desc' },
  filterKeys: ['show_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const eventId = computed<number | null>(() => {
  const raw = list.state.value.filters.show_id;
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
});

const editing = ref<ExtraRegistration | null>(null);
const formOpen = ref(false);

function openEdit(row: ExtraRegistration): void {
  editing.value = row;
  formOpen.value = true;
}

function save(values: FormValues): Promise<ExtraRegistration> {
  return extraRegistrationsApi.update((editing.value as ExtraRegistration).id, toInput(values));
}

function onSaved(): void {
  toast.success(`Quantity for ${editing.value?.user_name ?? 'the member'} saved.`);
  void list.reload();
}

function onEventFilter(option: RelationOption | null): void {
  void list.setFilter('show_id', option === null ? null : String(option.id));
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
  try {
    await extraRegistrationsApi.remove(row.id);
    toast.success('Extra purchase deleted.');
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The purchase could not be deleted.').message);
  }
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Extra registrations
    </h1>

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
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="sm:w-64">
          <label
            for="extra-registration-filter-event"
            class="block text-sm font-medium text-text"
          >Event</label>
          <div class="mt-1">
            <RelationSelect
              id="extra-registration-filter-event"
              :endpoint="`${ADMIN_API_BASE}/lookups/events`"
              :model-value="eventId"
              placeholder="Any event"
              @change="onEventFilter"
            />
          </div>
        </div>
      </template>

      <template #actions="{ row }">
        <div class="inline-flex flex-wrap justify-end gap-1">
          <BaseButton
            size="sm"
            :aria-label="`Edit quantity for ${row.user_name ?? row.id}`"
            @click="openEdit(row)"
          >
            Edit
          </BaseButton>
          <BaseButton
            size="sm"
            variant="danger"
            :aria-label="`Delete ${row.extra_name ?? 'extra'} for ${row.user_name ?? row.id}`"
            @click="remove(row)"
          >
            Delete
          </BaseButton>
        </div>
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      title="Edit quantity"
      :fields="EXTRA_REGISTRATION_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      submit-label="Save changes"
      size="md"
      @saved="onSaved"
    />
  </div>
</template>
