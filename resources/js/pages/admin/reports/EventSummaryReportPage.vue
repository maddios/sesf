<script setup lang="ts">
/**
 * Event summary (legacy EventSummary): totals for one event and its registrations with address, cars, guests,
 * shirts, extras and arrival. Opens on the most recent event. The export is the legacy multi-sheet workbook.
 */
import { onMounted, ref, watch } from 'vue';
import { api } from '../../../api/client';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import EventFilter from '../../../components/admin/EventFilter.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useToast } from '../../../composables/admin/useToast';
import { adminUrl, defaultEventId, fetchList, type EventRegistrationRow, type EventSummaryTotals } from './reports';
import type { ListQuery } from '../../../composables/admin/useAdminResource';

const EMPTY: ListResponse<EventRegistrationRow> = { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } };

/** Legacy column tooltips (event_summary.blade.php), kept verbatim. */
const TOTALS: Array<{ key: keyof EventSummaryTotals; label: string; title?: string }> = [
  { key: 'registrations', label: 'Total registrants' },
  { key: 'guests', label: 'Total guests' },
  { key: 'paid_registrations', label: 'Paid registrants' },
  { key: 'paid_guests', label: 'Paid guests', title: 'guests of registrants marked as paid' },
  { key: 'extras', label: 'Total extras', title: 'perf center or w/e the name is for a particular year' },
  { key: 'paid_extras', label: 'Total extras paid' },
  { key: 'tshirts', label: 'Number of tshirts ordered', title: 'and then maybe a link to breakdown of sizes ordered' },
  { key: 'paid_tshirts', label: 'Number of tshirts paid', title: 'same deal  then maybe a link to breakdown of sizes ordered and paid' },
];

const COLUMNS = [
  { key: 'invoice_id', label: 'Reg #', sortable: true, align: 'right' as const },
  { key: 'first', label: 'First' },
  { key: 'last', label: 'Last' },
  { key: 'address', label: 'Address' },
  { key: 'is_paid', label: 'Has Paid', sortable: true },
  { key: 'cars', label: 'Cars' },
  { key: 'guests', label: 'Guests' },
  { key: 'shirts', label: 'Shirts' },
  { key: 'extras', label: 'Extras' },
  { key: 'arrival', label: 'Arrival Time' },
];

const PAGE_SIZE = 100;

const showId = ref<number | null>(null);
const totals = ref<EventSummaryTotals | null>(null);
const showAll = ref(false);
const toast = useToast();

/** Every row of the event, fetched page by page (legacy showed all rows on one page). */
async function fetchAllRows(query: ListQuery, id: number): Promise<ListResponse<EventRegistrationRow>> {
  const rows: EventRegistrationRow[] = [];
  let page = 1;
  let lastPage: number;
  do {
    const body = await fetchList<EventRegistrationRow>('reports/event-summary', { ...query, page, per_page: PAGE_SIZE }, { show_id: String(id) });
    rows.push(...body.data);
    lastPage = body.meta.last_page;
    page += 1;
  } while (page <= lastPage);

  return { data: rows, meta: { page: 1, per_page: Math.max(rows.length, PAGE_SIZE), total: rows.length, last_page: 1 } };
}

const list = useAdminList<EventRegistrationRow>({
  fetch: (query) => {
    if (showId.value === null) return Promise.resolve(EMPTY);
    return showAll.value ? fetchAllRows(query, showId.value) : fetchList<EventRegistrationRow>('reports/event-summary', query, { show_id: String(showId.value) });
  },
  defaults: { sort: 'invoice_id', direction: 'asc', perPage: PAGE_SIZE },
});

function toggleShowAll(): void {
  showAll.value = !showAll.value;
  void list.reload();
}

async function loadTotals(): Promise<void> {
  if (showId.value === null) {
    totals.value = null;
    return;
  }
  try {
    const body = await api.get<{ data: EventSummaryTotals }>(adminUrl('reports/event-summary/totals', {}, { show_id: String(showId.value) }));
    totals.value = body.data;
  } catch (error) {
    toast.error(mapAdminError(error, 'The totals could not be loaded.').message);
  }
}

watch(showId, () => {
  void list.reload();
  void loadTotals();
});

onMounted(async () => {
  try {
    showId.value = await defaultEventId();
  } catch (error) {
    toast.error(mapAdminError(error, 'The events could not be loaded.').message);
  }
});

function exportHref(): string {
  return adminUrl('reports/event-summary/export.xls', {}, { show_id: String(showId.value) });
}

function onEvent(value: number | null): void {
  showId.value = value;
}

function cityLine(row: EventRegistrationRow): string {
  return [row.city, [row.state, row.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ');
}
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      Event summary
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
        Export event summary to Excel
      </a>
    </EventFilter>

    <p
      v-if="showId === null"
      class="a2-well"
    >
      Choose an event to see its summary.
    </p>

    <template v-else>
      <h2 class="a2-legend">
        Result
      </h2>
      <div
        v-if="totals"
        class="a2-scroll"
      >
        <table
          class="a2-table"
          aria-label="Event totals"
        >
          <thead>
            <tr>
              <th
                v-for="(column, index) in TOTALS"
                :key="column.key"
                scope="col"
                :title="column.title"
                :class="index === 0 ? 'a2-sticky-col' : ''"
              >
                {{ column.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td
                v-for="(column, index) in TOTALS"
                :key="column.key"
                :class="index === 0 ? 'a2-sticky-col' : ''"
              >
                {{ totals[column.key] }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminDataTable
        label="Event registrations"
        search-label="Search registrations"
        :searchable="false"
        :columns="COLUMNS"
        :rows="list.rows.value"
        :meta="list.meta.value"
        :state="list.state.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No registrations for this event."
        @sort="list.setSort"
        @page="list.setPage"
        @per-page="list.setPerPage"
        @retry="list.reload"
      >
        <template #toolbar-end>
          <button
            type="button"
            class="a2-btn"
            @click="toggleShowAll"
          >
            {{ showAll ? 'Show pages' : 'Show all rows' }}
          </button>
        </template>
        <template #cell-address="{ row }">
          {{ row.address1 }}
          <template v-if="row.address2">
            <br>{{ row.address2 }}
          </template>
          <br>{{ cityLine(row) }}
        </template>
        <template #cell-is_paid="{ row }">
          {{ row.is_paid ? '1' : '0' }}
        </template>
        <template #cell-cars="{ row }">
          <ol class="list-decimal pl-4">
            <li
              v-for="(car, index) in row.cars"
              :key="index"
            >
              {{ car }}
            </li>
          </ol>
        </template>
        <template #cell-guests="{ row }">
          <ol class="list-decimal pl-4">
            <li
              v-for="(guest, index) in row.guests"
              :key="index"
            >
              {{ guest.name }}
            </li>
          </ol>
        </template>
        <template #cell-shirts="{ row }">
          <ol class="list-decimal pl-4">
            <li
              v-for="(shirt, index) in row.shirts"
              :key="index"
            >
              {{ shirt ?? '' }}
            </li>
          </ol>
        </template>
        <template #cell-extras="{ row }">
          <ol class="list-decimal pl-4">
            <li
              v-for="(extra, index) in row.extras"
              :key="index"
            >
              {{ extra.count }}x {{ extra.name }}
            </li>
          </ol>
        </template>
        <template #cell-arrival="{ row }">
          {{ row.arrival_time ?? '' }} {{ row.arrival_date ?? '' }}
        </template>
      </AdminDataTable>
    </template>
  </div>
</template>
