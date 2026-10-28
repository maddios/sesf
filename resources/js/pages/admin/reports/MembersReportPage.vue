<script setup lang="ts">
/**
 * Members report (legacy MemberList): members filtered by event, paid status and notification preferences, with an
 * export of the same rows (SpreadsheetML .xls). The filters and sort sync with the URL.
 */
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
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

function onFilter(key: string, value: string | number | null): void {
  void list.setFilter(key, value === '' || value === null ? null : String(value));
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
  <div class="a2-page">
    <h1 class="sr-only">
      Members report
    </h1>

    <h2 class="a2-legend">
      Filter
    </h2>
    <form
      class="flex flex-wrap items-end gap-3"
      aria-label="Member filters"
      @submit.prevent
    >
      <div class="w-64">
        <label for="members-filter-show">Event</label>
        <RelationSelect
          id="members-filter-show"
          :endpoint="`${ADMIN_API_BASE}/lookups/events`"
          :model-value="showFilterValue()"
          placeholder="Any event"
          @update:model-value="(value) => onFilter('show_id', value)"
        />
      </div>
      <div class="w-40">
        <label for="members-filter-paid">Paid</label>
        <BaseCombobox
          id="members-filter-paid"
          :model-value="filterValue('is_paid')"
          :options="YES_NO_OPTIONS"
          @update:model-value="(value) => onFilter('is_paid', value)"
        />
      </div>
      <fieldset class="contents">
        <legend class="sr-only">
          User Preferences
        </legend>
        <div
          v-for="pref in PREFERENCES"
          :key="pref.key"
          class="w-64"
        >
          <label :for="`members-filter-${pref.key}`">{{ pref.label }}</label>
          <BaseCombobox
            :id="`members-filter-${pref.key}`"
            :model-value="filterValue(pref.key)"
            :options="PREFERENCE_OPTIONS"
            @update:model-value="(value) => onFilter(pref.key, value)"
          />
        </div>
      </fieldset>
    </form>

    <h2 class="a2-legend">
      Result
    </h2>
    <p>
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
        <a
          class="a2-btn"
          :href="exportHref()"
        >
          Export members to Excel
        </a>
      </template>
    </AdminDataTable>
  </div>
</template>
