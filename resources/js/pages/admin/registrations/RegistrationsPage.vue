<script setup lang="ts">
/**
 * Event registrations (admin). List with counts, event and status filters and a member-name filter (URL-synced),
 * edit of the legacy admin fields (donation, arrival, cancellation, invoice number, cars, payments, discount, paid
 * and owed overrides), cancel/reinstate, delete (409 when money or waivers are recorded: cancel instead), add a
 * guest or an extra, a new registration for a member, and a link to the full on-behalf editor. Ended shows show
 * stored amounts ("stored" badge). Guests are edited on the registration guests page.
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import RegisterForEventModal from '../users/RegisterForEventModal.vue';
import { GUEST_FIELDS, registrationGuestsApi, toInput as toGuestInput } from '../registration-guests/registration-guests';
import { extraRegistrationsApi } from '../extra-registrations/extraRegistrations';
import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { Extra } from '../extras/extras';
import type { AdminField } from '../../../components/admin/types';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { api } from '../../../api/client';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import {
  REGISTRATION_COLUMNS,
  REGISTRATION_FIELDS,
  cancelInput,
  formatCents,
  registrationCarsUrl,
  registrationsApi,
  toForm,
  toInput,
  type Registration,
  type RegistrationCarOption,
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
const carOptions = ref<RegistrationCarOption[]>([]);
const creating = ref(false);
const guestFor = ref<Registration | null>(null);
const guestOpen = ref(false);
const extraFor = ref<Registration | null>(null);
const extraOpen = ref(false);
const extraFields = ref<AdminField[]>([]);
const extrasApi = useAdminResource<Extra>('extras');

function onCreated(): void {
  toast.success('Registration created.');
  void list.reload();
}

function openAddGuest(registration: Registration): void {
  guestFor.value = registration;
  guestOpen.value = true;
}

function addGuest(values: FormValues): Promise<unknown> {
  return registrationGuestsApi.create({ ...toGuestInput(values), registration_id: (guestFor.value as Registration).id });
}

function onGuestAdded(): void {
  toast.success(`Guest added to registration #${guestFor.value?.invoice_id ?? ''}.`);
  void list.reload();
}

async function openAddExtra(registration: Registration): Promise<void> {
  extraFor.value = registration;
  try {
    const extras = await extrasApi.list({ per_page: 100, sort: 'name', direction: 'asc', filter: { show_id: String(registration.show_id) } });
    extraFields.value = [
      { name: 'show_extra_id', label: 'Extra', type: 'select', required: true, options: extras.data.map((extra) => ({ value: String(extra.id), label: extra.name })) },
      { name: 'num_extras', label: 'Quantity', type: 'number', required: true },
    ];
  } catch (error) {
    toast.error(mapAdminError(error, 'The event’s extras could not be loaded.').message);
    return;
  }
  extraOpen.value = true;
}

function addExtra(values: FormValues): Promise<unknown> {
  return extraRegistrationsApi.create({
    registration_id: (extraFor.value as Registration).id,
    show_extra_id: Number(values.show_extra_id),
    num_extras: typeof values.num_extras === 'number' ? values.num_extras : null,
  });
}

function onExtraAdded(): void {
  toast.success(`Extra added to registration #${extraFor.value?.invoice_id ?? ''}.`);
  void list.reload();
}

async function remove(registration: Registration): Promise<void> {
  const ok = await confirm({
    title: 'Delete registration',
    message: `Delete registration #${registration.invoice_id} of ${registration.user_name ?? 'this member'} with its cars, guests, shirts and extras? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = registration.id;
  try {
    await registrationsApi.remove(registration.id);
    toast.success(`Registration #${registration.invoice_id} deleted.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The registration could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}

async function loadCars(registration: Registration, userId?: number): Promise<boolean> {
  try {
    carOptions.value = (await api.get<{ data: RegistrationCarOption[] }>(registrationCarsUrl(registration.id, userId))).data;
    return true;
  } catch (error) {
    toast.error(mapAdminError(error, 'The member’s cars could not be loaded.').message);
    return false;
  }
}

async function openEdit(registration: Registration): Promise<void> {
  editing.value = registration;
  if (await loadCars(registration)) formOpen.value = true;
}

/** Reassigning: the car choices become the new member's cars (plus those already on the registration). */
function onMember(userId: number | null, setValue: (value: unknown) => void): void {
  setValue(userId);
  if (userId !== null && editing.value !== null) void loadCars(editing.value, userId);
}

function toggleCar(selected: unknown, id: number, on: boolean): number[] {
  const ids = Array.isArray(selected) ? selected.map(Number).filter((carId) => carId !== id) : [];
  return on ? [...ids, id] : ids;
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

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          @click="creating = true"
        >
          New registration
        </BaseButton>
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
            :to="row.editor_path"
            :aria-label="`Open the full editor for registration ${row.invoice_id}`"
          >
            Full editor
          </BaseButton>
          <BaseButton
            size="sm"
            :aria-label="`Add a guest to registration ${row.invoice_id}`"
            @click="openAddGuest(row)"
          >
            Add guest
          </BaseButton>
          <BaseButton
            size="sm"
            :aria-label="`Add an extra to registration ${row.invoice_id}`"
            @click="openAddExtra(row)"
          >
            Add extra
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
          <BaseButton
            size="sm"
            variant="danger"
            :loading="busyId === row.id"
            :aria-label="`Delete registration ${row.invoice_id}`"
            @click="remove(row)"
          >
            Delete
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
    >
      <template #field-user_id="{ id, value, errors, setValue }">
        <label
          :for="id"
          class="block text-sm font-medium text-text"
        >Member (required)</label>
        <RelationSelect
          :id="id"
          :model-value="typeof value === 'number' ? value : null"
          :endpoint="`${ADMIN_API_BASE}/users/lookup`"
          placeholder="Search members"
          @update:model-value="(picked: number | null) => onMember(picked, setValue)"
        />
        <p class="mt-1 text-sm text-muted">
          Another member takes over the registration with its guests and shirts.
        </p>
        <p
          v-for="(message, index) in errors"
          :key="index"
          class="text-sm text-danger-fg"
        >
          {{ message }}
        </p>
      </template>

      <template #field-car_ids="{ id, value, errors, setValue }">
        <fieldset :aria-describedby="errors.length > 0 ? `${id}-errors` : undefined">
          <legend class="block text-sm font-medium text-text">
            Cars
          </legend>
          <p
            v-if="carOptions.length === 0"
            class="mt-1 text-sm text-muted"
          >
            This member has no cars.
          </p>
          <label
            v-for="car in carOptions"
            :key="car.id"
            class="mt-1 flex items-center gap-2 text-sm"
          >
            <input
              type="checkbox"
              :checked="Array.isArray(value) && value.includes(car.id)"
              @change="setValue(toggleCar(value, car.id, ($event.target as HTMLInputElement).checked))"
            >
            {{ car.label }}
          </label>
          <div :id="`${id}-errors`">
            <p
              v-for="(message, index) in errors"
              :key="index"
              class="text-sm text-danger-fg"
            >
              {{ message }}
            </p>
          </div>
        </fieldset>
      </template>
    </AdminFormModal>

    <RegisterForEventModal
      v-model:open="creating"
      :member="null"
      @registered="onCreated"
    />

    <AdminFormModal
      v-model:open="guestOpen"
      :title="guestFor === null ? 'Add guest' : `Add a guest to registration ${guestFor.invoice_id}`"
      :fields="GUEST_FIELDS"
      :initial="{ type_id: '1' }"
      :submit="addGuest"
      submit-label="Add guest"
      size="md"
      @saved="onGuestAdded"
    />

    <AdminFormModal
      v-model:open="extraOpen"
      :title="extraFor === null ? 'Add extra' : `Add an extra to registration ${extraFor.invoice_id}`"
      :fields="extraFields"
      :initial="{ num_extras: 1 }"
      :submit="addExtra"
      submit-label="Add extra"
      size="md"
      @saved="onExtraAdded"
    />
  </div>
</template>
