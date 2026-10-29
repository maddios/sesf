<script setup lang="ts">
/**
 * Waivers per event, laid out like the legacy /admin2 waivers_list: event filter (with the Signed filter and the
 * check-in sort), the bulk bar (regenerate missing PDFs, send waiver emails to the selected people, remind every
 * incomplete party), the roster (cards on phones) and the signed walk-in / QR waivers. Open signs the person's
 * waiver; PDF opens a signed one. Sends and PDF work are queued on the server.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EventFilter from '../../../components/admin/EventFilter.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { PER_PAGE_OPTIONS, useAdminList } from '../../../composables/admin/useAdminList';
import { mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { defaultEventId } from '../reports/reports';
import { STATUS_OPTIONS, formatDateTime, personOf, waiversApi, type RosterRow, type WalkInRow } from './waivers';

const EMPTY: ListResponse<RosterRow> = { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } };
const PER_PAGE_CHOICES = PER_PAGE_OPTIONS.map((perPage) => ({ value: perPage, label: String(perPage) }));
const SEARCH_DEBOUNCE_MS = 300;

const showId = ref<number | null>(null);
const sortCheckedIn = ref(false);
const walkIns = ref<WalkInRow[]>([]);
const selected = ref<string[]>([]);
const busy = ref<'send' | 'reminders' | 'pdfs' | null>(null);
const toast = useToast();
const { confirm } = useConfirm();

const list = useAdminList<RosterRow>({
  fetch: (query) => (showId.value === null ? Promise.resolve(EMPTY) : waiversApi.roster(showId.value, query, sortCheckedIn.value)),
  defaults: { sort: 'last', direction: 'asc' },
  filterKeys: ['status'],
});

const searchText = ref(list.state.value.search);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const COLUMNS = computed(() => [
  { key: 'invoice_id', label: 'Invoice ID', sortable: true },
  { key: 'first', label: 'First Name', sortable: true },
  { key: 'last', label: 'Last Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'status', label: 'Signed', sortable: true },
  { key: 'arrival', label: 'Arriving', sortable: true },
  ...(sortCheckedIn.value ? [{ key: 'date_checked_in', label: 'Checked in', sortable: false }] : []),
  { key: 'signed_at', label: 'Date signed', sortable: true },
]);

const selectedRows = computed(() => list.rows.value.filter((row) => selected.value.includes(row.row_key)));

async function loadWalkIns(): Promise<void> {
  if (showId.value === null) {
    walkIns.value = [];
    return;
  }
  try {
    walkIns.value = await waiversApi.walkIns(showId.value);
  } catch (error) {
    toast.error(mapAdminError(error, 'Walk-in waivers could not be loaded.').message);
  }
}

function reloadAll(): void {
  selected.value = [];
  void list.reload();
  void loadWalkIns();
}

watch(showId, reloadAll);

watch(sortCheckedIn, () => {
  void list.reload();
});

onMounted(async () => {
  try {
    showId.value = await defaultEventId();
  } catch (error) {
    toast.error(mapAdminError(error, 'The events could not be loaded.').message);
  }
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function onEvent(value: number | null): void {
  showId.value = value;
}

function onStatus(value: string | number | null): void {
  void list.setFilter('status', value === null || value === '' ? null : String(value));
}

function statusValue(): string {
  return list.state.value.filters.status ?? '';
}

function onSearch(value: string): void {
  searchText.value = value;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void list.setSearch(value), SEARCH_DEBOUNCE_MS);
}

function clearSearch(): void {
  clearTimeout(searchTimer);
  searchText.value = '';
  void list.setSearch('');
}

function onPerPage(value: string | number | null): void {
  if (value !== null) void list.setPerPage(Number(value));
}

function ariaSort(key: string): 'ascending' | 'descending' | 'none' {
  if (list.state.value.sort !== key) return 'none';
  return list.state.value.direction === 'asc' ? 'ascending' : 'descending';
}

function sortMark(key: string): string {
  if (list.state.value.sort !== key) return '⇅';
  return list.state.value.direction === 'asc' ? '▲' : '▼';
}

function openSignUrl(row: RosterRow): string {
  return showId.value === null ? '#' : waiversApi.openSignUrl(showId.value, row);
}

function blankPdfUrl(): string {
  return showId.value === null ? '#' : waiversApi.blankPdfUrl(showId.value);
}

function fullName(row: RosterRow): string {
  return `${row.first} ${row.last}`.trim();
}

function selectAll(): void {
  selected.value = list.rows.value.map((row) => row.row_key);
}

function unselectAll(): void {
  selected.value = [];
}

function toggle(row: RosterRow, checked: boolean): void {
  selected.value = checked
    ? [...selected.value, row.row_key]
    : selected.value.filter((key) => key !== row.row_key);
}

async function sendSelected(): Promise<void> {
  if (showId.value === null || selectedRows.value.length === 0) return;
  const ok = await confirm({
    title: 'Send waiver emails',
    message: 'Send waiver request emails with the internal sign link to selected users?',
    confirmLabel: 'Send waiver emails',
  });
  if (!ok) return;
  busy.value = 'send';
  try {
    const body = await waiversApi.sendRequests(showId.value, selectedRows.value.filter((row) => row.email !== null).map(personOf));
    toast.success(`${body.data.queued} waiver email(s) queued.`);
    selected.value = [];
  } catch (error) {
    toast.error(mapAdminError(error, 'The waiver emails could not be queued.').message);
  } finally {
    busy.value = null;
  }
}

async function sendReminders(): Promise<void> {
  if (showId.value === null) return;
  const ok = await confirm({
    title: 'Send waiver reminders',
    message: 'Email each registrant (primary account) a reminder to open the waiver portal if anyone in their party still needs to sign?',
    confirmLabel: 'Send reminders',
  });
  if (!ok) return;
  busy.value = 'reminders';
  try {
    const body = await waiversApi.sendReminders(showId.value);
    if (body.data.queued > 0) {
      toast.success(`Queued waiver reminder emails for ${body.data.queued} registrant(s) with at least one unsigned waiver in their party.`);
    } else {
      toast.info('No registrants with incomplete party waivers for this event—nothing to send.');
    }
  } catch (error) {
    toast.error(mapAdminError(error, 'Could not send reminders.').message);
  } finally {
    busy.value = null;
  }
}

async function regeneratePdfs(): Promise<void> {
  if (showId.value === null) return;
  const ok = await confirm({
    title: 'Regenerate missing PDFs',
    message: 'Regenerate PDF files on disk for signed rows that are missing a PDF?',
    confirmLabel: 'Regenerate',
  });
  if (!ok) return;
  busy.value = 'pdfs';
  try {
    await waiversApi.regeneratePdfs(showId.value);
    toast.success('PDF regeneration queued.');
  } catch (error) {
    toast.error(mapAdminError(error, 'PDF regeneration could not be queued.').message);
  } finally {
    busy.value = null;
  }
}
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      Waivers
    </h1>

    <EventFilter
      :model-value="showId"
      @update:model-value="onEvent"
    >
      <div>
        <label
          for="waivers-filter-status"
          class="a2-small"
        >Signed</label>
        <BaseCombobox
          id="waivers-filter-status"
          :model-value="statusValue()"
          :options="STATUS_OPTIONS"
          @update:model-value="onStatus"
        />
      </div>
      <label class="a2-check">
        <input
          v-model="sortCheckedIn"
          type="checkbox"
          name="sort_checked_in"
        >
        Sort by check-in time (most recent first; not checked in at bottom)
      </label>
    </EventFilter>

    <p
      v-if="showId === null"
      class="a2-well"
    >
      Choose an event to see its waivers.
    </p>

    <template v-else>
      <div class="mb-3 flex flex-wrap gap-2">
        <button
          type="button"
          class="a2-btn a2-btn-info"
          :disabled="busy !== null"
          @click="regeneratePdfs"
        >
          Regenerate missing PDFs
        </button>
        <button
          type="button"
          class="a2-btn a2-btn-info"
          :disabled="busy !== null || selected.length === 0"
          @click="sendSelected"
        >
          Send waiver emails
        </button>
        <button
          type="button"
          class="a2-btn a2-btn-info"
          :disabled="busy !== null"
          @click="sendReminders"
        >
          Send waiver reminders
        </button>
      </div>

      <h2 class="a2-legend">
        Result
      </h2>

      <div class="a2-toolbar">
        <span class="a2-toolbar-count"><b>Num Users: {{ list.meta.value.total }}</b></span>
        <span class="a2-links">
          <BaseButton
            variant="link"
            size="sm"
            @click="selectAll"
          >
            Select all
          </BaseButton>
          <BaseButton
            variant="link"
            size="sm"
            @click="unselectAll"
          >
            Unselect all
          </BaseButton>
        </span>
      </div>

      <div class="a2-search">
        <label
          for="waivers-search"
          class="a2-search-label"
        >Search waivers</label>
        <div class="a2-search-field">
          <input
            id="waivers-search"
            type="search"
            autocomplete="off"
            class="a2-input"
            :value="searchText"
            @input="onSearch(($event.target as HTMLInputElement).value)"
          >
          <button
            v-if="searchText.trim() !== ''"
            type="button"
            class="a2-search-clear"
            aria-label="Clear search"
            title="Clear search"
            @click="clearSearch"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </div>

      <p
        v-if="list.error.value"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ list.error.value }}
        <BaseButton
          size="sm"
          @click="list.reload"
        >
          Try again
        </BaseButton>
      </p>
      <p
        v-else-if="list.loading.value && list.rows.value.length === 0"
        class="a2-muted"
      >
        Loading…
      </p>

      <table
        v-else
        class="a2-table a2-cards a2-cards--pair"
        aria-label="Waivers"
      >
        <thead>
          <tr>
            <th
              v-for="column in COLUMNS"
              :key="column.key"
              scope="col"
              :aria-sort="column.sortable ? ariaSort(column.key) : undefined"
            >
              <BaseButton
                v-if="column.sortable"
                variant="link"
                size="sm"
                @click="list.setSort(column.key)"
              >
                {{ column.label }}
                <span aria-hidden="true">{{ sortMark(column.key) }}</span>
              </BaseButton>
              <template v-else>
                {{ column.label }}
              </template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in list.rows.value"
            :key="row.row_key"
          >
            <td
              data-label="Invoice ID"
              class="a2-cell-title"
            >
              <label class="inline-flex items-center gap-2">
                <input
                  type="checkbox"
                  :aria-label="`Select ${fullName(row)}`"
                  :checked="selected.includes(row.row_key)"
                  @change="toggle(row, ($event.target as HTMLInputElement).checked)"
                >
                <span>{{ row.invoice_id }}</span>
              </label>
              <span
                v-if="row.kind === 'guest'"
                class="a2-label a2-label-info ml-2"
              >guest</span>
            </td>
            <td
              data-label="First Name"
              class="a2-cell-half"
            >
              {{ row.first }}
            </td>
            <td
              data-label="Last Name"
              class="a2-cell-half"
            >
              {{ row.last }}
            </td>
            <td data-label="Email">
              {{ row.email ?? '' }}
            </td>
            <td
              data-label="Signed"
              class="a2-cell-sep"
            >
              <template v-if="row.status === 'signed'">
                <span class="a2-label a2-label-success">Yes</span>
                <a
                  v-if="row.pdf_url"
                  :href="row.pdf_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="ml-1.5"
                  :aria-label="`Open signed waiver PDF for ${fullName(row)}`"
                >PDF</a>
              </template>
              <template v-else>
                <span
                  class="a2-label"
                  :class="row.status === 'not_signed' ? 'a2-label-warning' : 'a2-label-pending'"
                >{{ row.status === 'not_signed' ? 'Pending' : 'Not sent' }}</span>
                <a
                  :href="openSignUrl(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="ml-1.5"
                  :aria-label="`Open waiver for ${fullName(row)}`"
                >Open</a>
              </template>
            </td>
            <td
              data-label="Arriving"
              class="a2-cell-half"
            >
              {{ row.arrival ?? '' }}
            </td>
            <td
              v-if="sortCheckedIn"
              data-label="Checked in"
              class="a2-cell-half"
            >
              {{ formatDateTime(row.checked_in_at) }}
            </td>
            <td
              data-label="Date signed"
              class="a2-cell-half"
            >
              {{ row.status === 'signed' ? formatDateTime(row.signed_at) : '—' }}
            </td>
          </tr>
        </tbody>
      </table>
      <p
        v-if="!list.loading.value && !list.error.value && list.rows.value.length === 0"
        class="a2-muted"
      >
        No one is on this event's waiver list.
      </p>

      <nav
        class="mt-3 mb-6 flex flex-wrap items-center gap-2.5"
        aria-label="Roster pages"
      >
        <button
          type="button"
          class="a2-btn"
          :disabled="list.meta.value.page <= 1"
          @click="list.setPage(list.meta.value.page - 1)"
        >
          Previous
        </button>
        <span class="a2-muted">Page {{ list.meta.value.page }} of {{ list.meta.value.last_page }}</span>
        <button
          type="button"
          class="a2-btn"
          :disabled="list.meta.value.page >= list.meta.value.last_page"
          @click="list.setPage(list.meta.value.page + 1)"
        >
          Next
        </button>
        <label
          for="waivers-per-page"
          class="a2-small ml-auto"
        >Per page</label>
        <BaseCombobox
          id="waivers-per-page"
          class="w-28"
          :model-value="list.state.value.perPage"
          :options="PER_PAGE_CHOICES"
          @update:model-value="onPerPage"
        />
      </nav>

      <h2 class="a2-legend">
        Signed walk-in / QR waivers
      </h2>
      <p class="a2-links mb-2.5">
        <a
          class="a2-btn"
          :href="`/waiver_sign/s/${showId}`"
          target="_blank"
          rel="noopener noreferrer"
        >Open generic waiver for this event</a>
        <a
          class="a2-btn"
          :href="blankPdfUrl()"
          target="_blank"
          rel="noopener noreferrer"
        >Blank waiver PDF (print)</a>
      </p>
      <p
        v-if="walkIns.length === 0"
        class="a2-muted"
      >
        None for this event.
      </p>
      <template v-else>
        <table
          class="a2-table a2-cards"
          aria-label="Walk-in waivers"
        >
          <thead>
            <tr>
              <th scope="col">
                First name
              </th>
              <th scope="col">
                Last name
              </th>
              <th scope="col">
                Email
              </th>
              <th scope="col">
                Signed
              </th>
              <th scope="col">
                PDF
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="walkIn in walkIns"
              :key="walkIn.waiver_id ?? `${walkIn.first}-${walkIn.signed_at}`"
            >
              <td
                data-label="First name"
                class="a2-cell-title"
              >
                {{ walkIn.first }}
              </td>
              <td
                data-label="Last name"
                class="a2-cell-half"
              >
                {{ walkIn.last }}
              </td>
              <td data-label="Email">
                {{ walkIn.email ?? '' }}
              </td>
              <td
                data-label="Signed"
                class="a2-cell-half"
              >
                {{ formatDateTime(walkIn.signed_at) }}
              </td>
              <td
                data-label="PDF"
                class="a2-cell-half"
              >
                <a
                  v-if="walkIn.pdf_url"
                  :href="walkIn.pdf_url"
                  target="_blank"
                  rel="noopener noreferrer"
                >PDF</a>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="a2-small mt-2.5">
          <b>Count:</b> {{ walkIns.length }}
        </p>
      </template>
    </template>
  </div>
</template>
