<script setup lang="ts">
/**
 * Members report (legacy MemberList): members filtered by event, paid status and notification preferences, with an
 * export of the same rows (SpreadsheetML .xls). The filters and sort sync with the URL.
 */
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList, toListQuery } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE } from '../../../composables/admin/useAdminResource';
import { adminUrl, fetchList, PREFERENCE_OPTIONS, YES_NO_OPTIONS, type MemberRow } from './reports';

const COLUMNS = [
  { key: 'first', label: 'First Name', sortable: true },
  { key: 'last', label: 'Last Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
];

const PREFERENCES = [
  { key: 'allow_future_show_notifications', label: 'Allow future show notifications' },
  { key: 'allow_system_email', label: 'Allow system Email' },
  { key: 'allow_private_message', label: 'Allow private message' },
];

const list = useAdminList<MemberRow>({
  fetch: (query) => fetchList<MemberRow>('reports/members', query),
  defaults: { sort: 'last', direction: 'asc' },
  filterKeys: ['show_id', 'is_paid', ...PREFERENCES.map((p) => p.key)],
});

function filterValue(key: string): string {
  return list.state.value.filters[key] ?? '';
}

function onFilter(key: string, value: string | null): void {
  void list.setFilter(key, value === '' || value === null ? null : value);
}

function showFilterValue(): number | null {
  const raw = filterValue('show_id');
  return /^\d+$/.test(raw) ? Number(raw) : null;
}

function exportHref(): string {
  return adminUrl('reports/members/export.xls', toListQuery(list.state.value));
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Members report
    </h1>

    <form
      class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
      aria-label="Member filters"
      @submit.prevent
    >
      <div>
        <label
          for="members-filter-show"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="members-filter-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showFilterValue()"
            placeholder="Any event"
            @update:model-value="(value) => onFilter('show_id', value === null ? null : String(value))"
          />
        </div>
      </div>
      <div>
        <label
          for="members-filter-paid"
          class="block text-sm font-medium text-text"
        >Paid</label>
        <select
          id="members-filter-paid"
          class="form-control mt-1"
          :value="filterValue('is_paid')"
          @change="onFilter('is_paid', ($event.target as HTMLSelectElement).value)"
        >
          <option
            v-for="option in YES_NO_OPTIONS"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </option>
        </select>
      </div>
      <fieldset class="contents">
        <legend class="sr-only">
          User Preferences
        </legend>
        <div
          v-for="pref in PREFERENCES"
          :key="pref.key"
        >
          <label
            :for="`members-filter-${pref.key}`"
            class="block text-sm font-medium text-text"
          >{{ pref.label }}</label>
          <select
            :id="`members-filter-${pref.key}`"
            class="form-control mt-1"
            :value="filterValue(pref.key)"
            @change="onFilter(pref.key, ($event.target as HTMLSelectElement).value)"
          >
            <option
              v-for="option in PREFERENCE_OPTIONS"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
      </fieldset>
    </form>

    <p class="text-sm">
      <b>Num Users: {{ list.meta.value.total }}</b>
    </p>

    <AdminDataTable
      label="Members"
      search-label="Search members"
      :columns="COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No members match these filters."
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #toolbar-end>
        <BaseButton
          :href="exportHref()"
          aria-label="Export members to Excel"
        >
          Export .xls
        </BaseButton>
      </template>
    </AdminDataTable>
  </div>
</template>
