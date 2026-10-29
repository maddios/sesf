<script setup lang="ts">
/**
 * Event registrations (admin). List with counts, event and status filters and a member-name filter (URL-synced),
 * edit of the legacy admin fields (donation, arrival, cancellation, invoice number, cars, payments, discount, paid
 * and owed overrides; a 409 when received or discount changed since it was opened offers a reload), cancel/reinstate, delete (409 when money or waivers are recorded: cancel instead), add a
 * guest or an extra, a new registration for a member, and a link to the full on-behalf editor. Ended shows show
 * stored amounts ("stored" badge). Guests are edited on the registration guests page.
 */
import { nextTick, ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormField from '../../../components/admin/AdminFormField.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminRowActions from '../../../components/admin/AdminRowActions.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import RegisterForEventModal from '../users/RegisterForEventModal.vue';
import { GUEST_FIELDS, registrationGuestsApi, toInput as toGuestInput } from '../registration-guests/registration-guests';
import { extraRegistrationsApi } from '../extra-registrations/extraRegistrations';
import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { Extra } from '../extras/extras';
import type { AdminField } from '../../../components/admin/types';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { api, ApiError } from '../../../api/client';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { BUSY_HINT, rowActions, type FormValues, type RowAction } from '../../../components/admin/types';
import {
  REGISTRATION_CHANGED,
  REGISTRATION_COLUMNS,
  REGISTRATION_FIELDS,
  cancelInput,
  formatCents,
  registrationCarsUrl,
  registrationsApi,
  toForm,
  updateInput,
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

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: '0', label: 'Active' },
  { value: '1', label: 'Cancelled' },
];

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

function actionsFor(registration: Registration): RowAction[] {
  const busy = busyId.value === registration.id;
  const hint = busy ? BUSY_HINT : undefined;
  return rowActions(
    { id: 'editor', label: 'Open full editor', icon: 'external', href: `#${registration.editor_path}` },
    { id: 'guest', label: 'Add a guest', icon: 'user-plus', handler: () => openAddGuest(registration) },
    { id: 'extra', label: 'Add an extra', icon: 'plus', handler: () => openAddExtra(registration) },
    registration.is_cancelled
      ? { id: 'reinstate', label: 'Reinstate', icon: 'check', disabled: busy, hint, handler: () => setCancelled(registration, false) }
      : { id: 'cancel', label: 'Cancel registration…', icon: 'ban', danger: true, disabled: busy, hint, handler: () => setCancelled(registration, true) },
    { id: 'delete', label: 'Delete…', icon: 'trash', danger: true, disabled: busy, hint, handler: () => remove(registration) },
  );
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

function isChanged(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && (error.body as { code?: unknown } | null)?.code === REGISTRATION_CHANGED;
}

/** The registration changed under the open form (e.g. a payment): offer to reload it with its current values. */
async function offerReload(id: number): Promise<void> {
  const ok = await confirm({
    title: 'Registration changed',
    message: 'This registration was changed after you opened it (for example a payment came in). Reload it to see the current values? Your unsaved changes will be lost.',
    confirmLabel: 'Reload',
  });
  void list.reload();
  if (!ok) return;
  try {
    const fresh = await registrationsApi.get(id);
    formOpen.value = false;
    await nextTick();
    editing.value = fresh;
    formOpen.value = true;
  } catch (error) {
    toast.error(mapAdminError(error, 'The registration could not be reloaded.').message);
  }
}

async function save(values: FormValues): Promise<Registration> {
  const registration = editing.value as Registration;
  try {
    return await registrationsApi.update(registration.id, updateInput(registration, values));
  } catch (error) {
    if (isChanged(error)) void offerReload(registration.id);
    throw error;
  }
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
    if (isChanged(error)) void list.reload();
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
    <AdminPageHeader title="Registrations">
      <template #actions>
        <BaseButton
          variant="primary"
          @click="creating = true"
        >
          New registration
        </BaseButton>
      </template>
    </AdminPageHeader>

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
          <label for="registration-filter-show">Event</label>
          <RelationSelect
            id="registration-filter-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showFilterValue()"
            placeholder="Any event"
            @update:model-value="onShowFilter"
          />
        </div>
        <div class="w-40">
          <label for="registration-filter-cancelled">Status</label>
          <BaseCombobox
            id="registration-filter-cancelled"
            :model-value="list.state.value.filters.is_cancelled ?? ''"
            :options="STATUS_OPTIONS"
            @update:model-value="(value) => list.setFilter('is_cancelled', value === null ? '' : String(value))"
          />
        </div>
        <div class="w-48">
          <label for="registration-filter-member">Member name</label>
          <input
            id="registration-filter-member"
            class="form-control w-full"
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
        <AdminRowActions
          :subject="`registration ${row.invoice_id}`"
          :primary="{ id: 'edit', label: 'Edit', icon: 'edit', handler: () => openEdit(row) }"
          :actions="actionsFor(row)"
        />
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
        <AdminFormField
          :id="id"
          v-slot="{ inputProps }"
          label="Member"
          required
          :errors="errors"
          hint="Another member takes over the registration with its guests and shirts."
        >
          <RelationSelect
            v-bind="inputProps"
            :model-value="typeof value === 'number' ? value : null"
            :endpoint="`${ADMIN_API_BASE}/users/lookup`"
            placeholder="Search members"
            @update:model-value="(picked: number | null) => onMember(picked, setValue)"
          />
        </AdminFormField>
      </template>

      <template #field-car_ids="{ id, value, errors, setValue }">
        <div class="is-wide">
          <fieldset :aria-describedby="errors.length > 0 ? `${id}-errors` : undefined">
            <legend class="sr-only">
              Cars
            </legend>
            <p
              v-if="carOptions.length === 0"
              class="text-sm text-muted"
            >
              This member has no cars.
            </p>
            <div class="grid gap-x-4 sm:grid-cols-2">
              <label
                v-for="car in carOptions"
                :key="car.id"
                class="a2-check"
              >
                <input
                  type="checkbox"
                  :checked="Array.isArray(value) && value.includes(car.id)"
                  @change="setValue(toggleCar(value, car.id, ($event.target as HTMLInputElement).checked))"
                >
                {{ car.label }}
              </label>
            </div>
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
        </div>
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
