<script setup lang="ts">
/**
 * Members admin (see docs/admin-conventions.md). Lists active members by default; the status filter shows disabled
 * or all. "Add member" creates an account with a password. Per row: edit, register for an event, log in as the member
 * (audited; a banner shows the way back), set the password, send a password reset mail, disable or restore. Another
 * administrator's password cannot be set or reset (the server answers 403), so those buttons are hidden. Disabled members cannot be logged in as, and admin accounts cannot be
 * impersonated (the server answers 403 and the page shows that message).
 */
import { ref } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { USER_TYPE_ADMIN, useAuthStore } from '../../../stores/auth';
import type { FormValues } from '../../../components/admin/types';
import RegisterForEventModal from './RegisterForEventModal.vue';
import {
  CREATE_USER_FIELDS,
  PASSWORD_FIELDS,
  USER_COLUMNS,
  USER_FIELDS,
  USER_TYPE_OPTIONS,
  goToSite,
  toCreateInput,
  toForm,
  toInput,
  toPasswordInput,
  usersApi,
  type AdminUser,
} from './users';

const list = useAdminList<AdminUser>({
  fetch: (query) => usersApi.list(query),
  defaults: { sort: 'last', direction: 'asc' },
  filterKeys: ['status', 'type_id'],
});
const toast = useToast();
const { confirm } = useConfirm();
const auth = useAuthStore();

const editing = ref<AdminUser | null>(null);
const formOpen = ref(false);
const passwordFor = ref<AdminUser | null>(null);
const passwordOpen = ref(false);
const registerFor = ref<AdminUser | null>(null);
const registerOpen = ref(false);
const busyId = ref<number | null>(null);

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'disabled', label: 'Disabled' },
  { value: 'all', label: 'All' },
];

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(member: AdminUser): void {
  editing.value = member;
  formOpen.value = true;
}

function save(values: FormValues): Promise<AdminUser> {
  return editing.value === null ? usersApi.create(toCreateInput(values)) : usersApi.update(editing.value.id, toInput(values));
}

function onSaved(result: unknown): void {
  toast.success(`Member “${(result as AdminUser).full_name}” ${editing.value === null ? 'created' : 'saved'}.`);
  void list.reload();
}

/** Each administrator manages their own sign-in details. */
function managesSignIn(member: AdminUser): boolean {
  return member.type_id !== USER_TYPE_ADMIN || member.id === auth.userId;
}

function openPassword(member: AdminUser): void {
  passwordFor.value = member;
  passwordOpen.value = true;
}

function savePassword(values: FormValues): Promise<AdminUser> {
  return usersApi.action((passwordFor.value as AdminUser).id, 'password', toPasswordInput(values));
}

function onPasswordSaved(): void {
  toast.success(`Password changed for ${passwordFor.value?.full_name ?? 'the member'}.`);
}

function openRegister(member: AdminUser): void {
  registerFor.value = member;
  registerOpen.value = true;
}

function onRegistered(): void {
  toast.success(`Member “${registerFor.value?.full_name ?? ''}” registered for the event.`);
  void list.reload();
}

/** Runs one row action with busy state and the server's message on failure. */
async function run(member: AdminUser, work: () => Promise<unknown>, done: string): Promise<boolean> {
  busyId.value = member.id;
  try {
    await work();
    toast.success(done);
    return true;
  } catch (error) {
    toast.error(mapAdminError(error).message);
    return false;
  } finally {
    busyId.value = null;
  }
}

async function logInAs(member: AdminUser): Promise<void> {
  const ok = await confirm({
    title: 'Log in as this member',
    message: `You will see the site as ${member.full_name}. Your session returns to your account from the banner. This is recorded in the audit log.`,
    confirmLabel: 'Log in as member',
  });
  if (!ok) return;
  const started = await run(member, () => usersApi.action(member.id, 'impersonate'), `Now signed in as ${member.full_name}.`);
  if (started) goToSite();
}

async function sendReset(member: AdminUser): Promise<void> {
  const ok = await confirm({
    title: 'Send password reset',
    message: `Email ${member.full_name} a link to reset their password? Their current password stays until they use the link.`,
    confirmLabel: 'Send reset email',
  });
  if (!ok) return;
  await run(member, () => usersApi.action(member.id, 'password-reset'), `Reset email sent to ${member.full_name}.`);
}

async function disable(member: AdminUser): Promise<void> {
  const ok = await confirm({
    title: 'Disable member',
    message: `Disable ${member.full_name}? They cannot sign in until the account is restored.`,
    confirmLabel: 'Disable',
    danger: true,
  });
  if (!ok) return;
  if (await run(member, () => usersApi.remove(member.id), `Member “${member.full_name}” disabled.`)) {
    await list.reload();
  }
}

async function restore(member: AdminUser): Promise<void> {
  if (await run(member, () => usersApi.action(member.id, 'restore'), `Member “${member.full_name}” restored.`)) {
    await list.reload();
  }
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Members
    </h1>

    <AdminDataTable
      label="Members"
      search-label="Search members"
      :columns="USER_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No members match."
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div>
          <label
            for="user-filter-status"
            class="block text-sm font-medium text-text"
          >Status</label>
          <select
            id="user-filter-status"
            class="form-control mt-1"
            :value="list.state.value.filters.status ?? 'active'"
            @change="list.setFilter('status', ($event.target as HTMLSelectElement).value)"
          >
            <option
              v-for="option in STATUS_OPTIONS"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
        <div>
          <label
            for="user-filter-type"
            class="block text-sm font-medium text-text"
          >Account type</label>
          <select
            id="user-filter-type"
            class="form-control mt-1"
            :value="list.state.value.filters.type_id ?? ''"
            @change="list.setFilter('type_id', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              Any
            </option>
            <option
              v-for="option in USER_TYPE_OPTIONS"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
      </template>

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          Add member
        </BaseButton>
      </template>

      <template #cell-profile_url="{ row }">
        <a
          :href="row.profile_url"
          target="_blank"
          rel="noopener"
          class="text-link underline"
        >Profile<span class="sr-only"> of {{ row.full_name }} (opens in a new tab)</span></a>
      </template>

      <template #cell-is_disabled="{ row }">
        <StatusBadge
          :label="row.is_disabled ? 'Disabled' : 'Active'"
          :variant="row.is_disabled ? 'danger' : 'success'"
        />
      </template>

      <template #actions="{ row }">
        <div class="inline-flex flex-wrap justify-end gap-1">
          <BaseButton
            size="sm"
            :aria-label="`Edit ${row.full_name}`"
            @click="openEdit(row)"
          >
            Edit
          </BaseButton>
          <BaseButton
            v-if="!row.is_disabled"
            size="sm"
            :aria-label="`Register ${row.full_name} for an event`"
            @click="openRegister(row)"
          >
            Register
          </BaseButton>
          <BaseButton
            v-if="!row.is_disabled"
            size="sm"
            :loading="busyId === row.id"
            :aria-label="`Log in as ${row.full_name}`"
            @click="logInAs(row)"
          >
            Log in as
          </BaseButton>
          <BaseButton
            v-if="managesSignIn(row)"
            size="sm"
            :aria-label="`Set password for ${row.full_name}`"
            @click="openPassword(row)"
          >
            Set password
          </BaseButton>
          <BaseButton
            v-if="!row.is_disabled && managesSignIn(row)"
            size="sm"
            :loading="busyId === row.id"
            :aria-label="`Send password reset to ${row.full_name}`"
            @click="sendReset(row)"
          >
            Reset password
          </BaseButton>
          <BaseButton
            v-if="row.is_disabled"
            size="sm"
            :loading="busyId === row.id"
            :aria-label="`Restore ${row.full_name}`"
            @click="restore(row)"
          >
            Restore
          </BaseButton>
          <BaseButton
            v-else
            size="sm"
            variant="danger"
            :loading="busyId === row.id"
            :aria-label="`Disable ${row.full_name}`"
            @click="disable(row)"
          >
            Disable
          </BaseButton>
        </div>
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'Add member' : `Edit ${editing.full_name}`"
      :fields="editing === null ? CREATE_USER_FIELDS : USER_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create member' : 'Save changes'"
      @saved="onSaved"
    />

    <AdminFormModal
      v-model:open="passwordOpen"
      :title="`Set password for ${passwordFor?.full_name ?? 'member'}`"
      :fields="PASSWORD_FIELDS"
      :initial="{ password: '', password_confirmation: '' }"
      :submit="savePassword"
      submit-label="Set password"
      @saved="onPasswordSaved"
    />

    <RegisterForEventModal
      v-model:open="registerOpen"
      :member="registerFor"
      @registered="onRegistered"
    />
  </div>
</template>
