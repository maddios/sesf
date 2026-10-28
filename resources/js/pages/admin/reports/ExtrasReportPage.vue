<script setup lang="ts">
/**
 * Extras summary (legacy ExtraList): quantities bought and paid per extra for one event, with an export of the same rows.
 */
import { onMounted, ref, watch } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
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
  <div class="space-y-4">
    <h1 class="page-title">
      Extras summary
    </h1>

    <div class="flex flex-wrap items-end gap-3">
      <div class="sm:w-96">
        <label
          for="extras-event"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="extras-event"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showId"
            placeholder="Choose an event"
            @update:model-value="onEvent"
          />
        </div>
      </div>
      <BaseButton
        v-if="showId !== null"
        :href="exportHref()"
        aria-label="Export extras summary to Excel"
      >
        Export .xls
      </BaseButton>
    </div>

    <p
      v-if="showId === null"
      class="text-muted"
    >
      Choose an event to see its extras.
    </p>

    <AdminDataTable
      v-else
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
  </div>
</template>
