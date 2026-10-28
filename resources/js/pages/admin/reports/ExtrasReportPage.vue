<script setup lang="ts">
/**
 * Extras summary (legacy ExtraList): quantities bought and paid per extra for one event, with an export of the same rows.
 */
import { onMounted, ref, watch } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import EventFilter from '../../../components/admin/EventFilter.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useToast } from '../../../composables/admin/useToast';
import { adminUrl, defaultEventId, fetchList, type ExtraRow } from './reports';

const EMPTY: ListResponse<ExtraRow> = { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } };
const COLUMNS = [
  { key: 'id', label: 'Id', align: 'right' as const },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'num_total', label: 'Num Total', sortable: true, align: 'right' as const },
  { key: 'num_paid', label: 'Num Paid', sortable: true, align: 'right' as const },
];

const showId = ref<number | null>(null);
const toast = useToast();

const list = useAdminList<ExtraRow>({
  fetch: (query) => (showId.value === null ? Promise.resolve(EMPTY) : fetchList<ExtraRow>('reports/extras', query, { show_id: String(showId.value) })),
  defaults: { sort: 'name', direction: 'asc' },
});

watch(showId, () => {
  void list.reload();
});

onMounted(async () => {
  try {
    showId.value = await defaultEventId();
  } catch (error) {
    toast.error(mapAdminError(error, 'The events could not be loaded.').message);
  }
});

function onEvent(value: number | null): void {
  showId.value = value;
}

function exportHref(): string {
  return adminUrl('reports/extras/export.xls', {}, { show_id: String(showId.value) });
}
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      Extras summary
    </h1>

    <EventFilter
      :model-value="showId"
      @update:model-value="onEvent"
    >
      <a
        v-if="showId !== null"
        class="a2-btn"
        :href="exportHref()"
      >
        Export extras summary to Excel
      </a>
    </EventFilter>

    <p
      v-if="showId === null"
      class="a2-well"
    >
      Choose an event to see its extras.
    </p>

    <template v-else>
      <h2 class="a2-legend">
        Result
      </h2>
      <AdminDataTable
        label="Extras"
        search-label="Search extras"
        :columns="COLUMNS"
        :rows="list.rows.value"
        :meta="list.meta.value"
        :state="list.state.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="This event has no extras."
        @sort="list.setSort"
        @page="list.setPage"
        @search="list.setSearch"
        @per-page="list.setPerPage"
        @retry="list.reload"
      />
    </template>
  </div>
</template>
