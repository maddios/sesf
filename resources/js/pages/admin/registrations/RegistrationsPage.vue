<script setup lang="ts">
/**
 * Event registrations (admin). List with counts, event and status filters and a member-name filter (URL-synced),
 * edit of donation, arrival and cancellation, and a cancel/reinstate row action. Ended shows show stored amounts
 * ("stored" badge). Guests are edited on the registration guests page.
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import {
  REGISTRATION_COLUMNS,
  REGISTRATION_FIELDS,
  cancelInput,
  formatCents,
  registrationsApi,
  toForm,
  toInput,
  type Registration,
} from './registrations';

const list = useAdminList<Registration>({
  fetch: (query) => registrationsApi.list(query),
  defaults: { sort: 'invoice_id', direction: 'desc' },
  filterKeys: ['show_id', 'is_cancelled', 'member'],
});
const toast = useToast();
const { confirm } = useConfirm();

const editing = ref<Registration | null>(null);
const formOpen = ref(false);
const busyId = ref<number | null>(null);

function openEdit(registration: Registration): void {
  editing.value = registration;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Registration> {
  return registrationsApi.update((editing.value as Registration).id, toInput(values));
}

function onSaved(result: unknown): void {
  const registration = result as Registration;
  toast.success(`Registration #${registration.invoice_id} saved.`);
  void list.reload();
}

async function setCancelled(registration: Registration, cancelled: boolean): Promise<void> {
  const ok = await confirm({
    title: cancelled ? 'Cancel registration' : 'Reinstate registration',
    message: cancelled
      ? `Cancel the registration of ${registration.user_name ?? 'this member'} for ${registration.show_name}? Cars, guests and amounts are kept.`
      : `Reinstate the registration of ${registration.user_name ?? 'this member'} for ${registration.show_name}?`,
    confirmLabel: cancelled ? 'Cancel registration' : 'Reinstate',
    danger: cancelled,
  });
  if (!ok) return;
  busyId.value = registration.id;
  try {
    await registrationsApi.update(registration.id, cancelInput(registration, cancelled));
    toast.success(`Registration #${registration.invoice_id} ${cancelled ? 'cancelled' : 'reinstated'}.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error).message);
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
  <div class="space-y-4">
    <h1 class="page-title">
      Registrations
    </h1>

    <AdminDataTable
      label="Registrations"
      search-label="Search invoice number"
      :columns="REGISTRATION_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No registrations match."
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="sm:w-64">
          <label
            for="registration-filter-show"
            class="block text-sm font-medium text-text"
          >Event</label>
          <div class="mt-1">
            <RelationSelect
              id="registration-filter-show"
              :endpoint="`${ADMIN_API_BASE}/lookups/events`"
              :model-value="showFilterValue()"
              placeholder="Any event"
              @update:model-value="onShowFilter"
            />
          </div>
        </div>
        <div>
          <label
            for="registration-filter-cancelled"
            class="block text-sm font-medium text-text"
          >Status</label>
          <select
            id="registration-filter-cancelled"
            class="form-control mt-1"
            :value="list.state.value.filters.is_cancelled ?? ''"
            @change="list.setFilter('is_cancelled', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              All
            </option>
            <option value="0">
              Active
            </option>
            <option value="1">
              Cancelled
            </option>
          </select>
        </div>
        <div class="sm:w-56">
          <label
            for="registration-filter-member"
            class="block text-sm font-medium text-text"
          >Member name</label>
          <input
            id="registration-filter-member"
            class="form-control mt-1 w-full"
            type="search"
            :value="list.state.value.filters.member ?? ''"
            @change="list.setFilter('member', ($event.target as HTMLInputElement).value)"
          >
        </div>
      </template>

      <template #cell-total_cents="{ row }">
        <span>{{ formatCents(row.total_cents) }}</span>
        <span
          v-if="row.amount_source === 'stored'"
          class="ml-1 text-xs text-muted"
        >stored</span>
      </template>
      <template #cell-is_cancelled="{ row }">
        <StatusBadge
          :label="row.is_cancelled ? 'Cancelled' : 'Active'"
          :variant="row.is_cancelled ? 'danger' : 'success'"
        />
      </template>

      <template #actions="{ row }">
        <div class="inline-flex flex-wrap justify-end gap-1">
          <BaseButton
            size="sm"
            :aria-label="`Edit registration ${row.invoice_id}`"
            @click="openEdit(row)"
          >
            Edit
          </BaseButton>
          <BaseButton
            size="sm"
            :variant="row.is_cancelled ? 'primary' : 'danger'"
            :loading="busyId === row.id"
            :aria-label="`${row.is_cancelled ? 'Reinstate' : 'Cancel'} registration ${row.invoice_id}`"
            @click="setCancelled(row, !row.is_cancelled)"
          >
            {{ row.is_cancelled ? 'Reinstate' : 'Cancel' }}
          </BaseButton>
        </div>
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'Edit registration' : `Edit registration ${editing.invoice_id}`"
      :fields="REGISTRATION_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      submit-label="Save changes"
      @saved="onSaved"
    />
  </div>
</template>
