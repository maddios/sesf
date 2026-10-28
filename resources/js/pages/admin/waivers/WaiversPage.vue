<script setup lang="ts">
/**
 * Waivers per event (legacy WaiversList). Opens on the most recent event. Each row has a checkbox; the bulk actions
 * regenerate missing PDFs, email the waiver request to the selected people, or remind every incomplete party. The
 * "Signed" filter and the check-in sort match the legacy page. Open signs the person's waiver; PDF opens a signed one.
 * Walk-in waivers signed at the event are listed below. Sends and PDF work are queued on the server.
 */
import { computed, onMounted, ref, watch } from 'vue';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError, type ListResponse } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { defaultEventId } from '../reports/reports';
import { STATUS_OPTIONS, formatDateTime, personOf, waiversApi, type RosterRow, type WalkInRow } from './waivers';

const EMPTY: ListResponse<RosterRow> = { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } };

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

const COLUMNS = computed(() => [
  { key: 'invoice_id', label: 'Invoice ID', sortable: true, align: 'left' as const },
  { key: 'first', label: 'First Name', sortable: true },
  { key: 'last', label: 'Last Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'status', label: 'Signed', sortable: true },
  { key: 'arrival', label: 'Arriving', sortable: true },
  ...(sortCheckedIn.value ? [{ key: 'date_checked_in', label: 'Checked in' }] : []),
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

function onEvent(value: number | null): void {
  showId.value = value;
}

function onStatus(value: string): void {
  void list.setFilter('status', value === '' ? null : value);
}

function statusValue(): string {
  return list.state.value.filters.status ?? '';
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
  <div class="space-y-4">
    <h1 class="page-title">
      Waivers
    </h1>

    <div class="sm:w-96">
      <label
        for="waivers-event"
        class="block text-sm font-medium text-text"
      >Event</label>
      <div class="mt-1">
        <RelationSelect
          id="waivers-event"
          :endpoint="`${ADMIN_API_BASE}/lookups/events`"
          :model-value="showId"
          placeholder="Choose an event"
          @update:model-value="onEvent"
        />
      </div>
    </div>

    <p
      v-if="showId === null"
      class="text-muted"
    >
      Choose an event to see its waivers.
    </p>

    <template v-else>
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label
            for="waivers-filter-status"
            class="block text-sm font-medium text-text"
          >Signed</label>
          <select
            id="waivers-filter-status"
            class="form-control mt-1"
            :value="statusValue()"
            @change="onStatus(($event.target as HTMLSelectElement).value)"
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
        <label class="flex items-center gap-2 text-sm text-text">
          <input
            v-model="sortCheckedIn"
            type="checkbox"
            name="sort_checked_in"
          >
          Sort by check-in time (most recent first; not checked in at bottom)
        </label>
      </div>

      <div class="flex flex-wrap gap-2">
        <BaseButton
          :loading="busy === 'pdfs'"
          loading-label="Queuing PDFs"
          @click="regeneratePdfs"
        >
          Regenerate missing PDFs
        </BaseButton>
        <BaseButton
          :loading="busy === 'send'"
          loading-label="Queuing emails"
          :disabled="selected.length === 0"
          @click="sendSelected"
        >
          Send waiver emails
        </BaseButton>
        <BaseButton
          :loading="busy === 'reminders'"
          loading-label="Queuing reminders"
          @click="sendReminders"
        >
          Send waiver reminders
        </BaseButton>
      </div>

      <p class="flex flex-wrap items-center gap-3 text-sm">
        <b>Num Users: {{ list.meta.value.total }}</b>
        <BaseButton
          variant="link"
          @click="selectAll"
        >
          Select all
        </BaseButton>
        <BaseButton
          variant="link"
          @click="unselectAll"
        >
          Unselect all
        </BaseButton>
      </p>

      <AdminDataTable
        label="Waivers"
        search-label="Search waivers"
        row-key="row_key"
        :columns="COLUMNS"
        :rows="list.rows.value"
        :meta="list.meta.value"
        :state="list.state.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No one is on this event's waiver list."
        @sort="list.setSort"
        @page="list.setPage"
        @search="list.setSearch"
        @per-page="list.setPerPage"
        @retry="list.reload"
      >
        <template #cell-invoice_id="{ row }">
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
            class="ml-2 rounded-sm bg-subtle px-1 text-xs"
          >guest</span>
        </template>
        <template #cell-status="{ row }">
          <span v-if="row.status === 'signed'">
            Yes
            <a
              v-if="row.pdf_url"
              :href="row.pdf_url"
              target="_blank"
              rel="noopener noreferrer"
              :aria-label="`Open signed waiver PDF for ${fullName(row)}`"
            >PDF</a>
          </span>
          <span v-else>
            {{ row.status === 'not_signed' ? 'Pending' : 'Not sent' }}
            <a
              :href="openSignUrl(row)"
              target="_blank"
              rel="noopener noreferrer"
              :aria-label="`Open waiver for ${fullName(row)}`"
            >Open</a>
          </span>
        </template>
        <template #cell-arrival="{ row }">
          {{ row.arrival ?? '' }}
        </template>
        <template #cell-date_checked_in="{ row }">
          {{ formatDateTime(row.checked_in_at) }}
        </template>
        <template #cell-signed_at="{ row }">
          {{ row.status === 'signed' ? formatDateTime(row.signed_at) : '—' }}
        </template>
        <template #cell-email="{ row }">
          {{ row.email ?? '' }}
        </template>
      </AdminDataTable>

      <section
        aria-labelledby="walk-ins-heading"
        class="space-y-3"
      >
        <h2
          id="walk-ins-heading"
          class="text-lg font-semibold text-heading"
        >
          Signed walk-in / QR waivers
        </h2>
        <p class="flex flex-wrap gap-3">
          <a
            class="btn"
            :href="`/waiver_sign/s/${showId}`"
            target="_blank"
            rel="noopener noreferrer"
          >Open generic waiver for this event</a>
          <a
            class="btn"
            :href="blankPdfUrl()"
            target="_blank"
            rel="noopener noreferrer"
          >Blank waiver PDF (print)</a>
        </p>
        <p
          v-if="walkIns.length === 0"
          class="text-muted"
        >
          None for this event.
        </p>
        <template v-else>
          <table
            class="w-full text-sm"
            aria-label="Walk-in waivers"
          >
            <thead>
              <tr>
                <th
                  scope="col"
                  class="text-left"
                >
                  First name
                </th>
                <th
                  scope="col"
                  class="text-left"
                >
                  Last name
                </th>
                <th
                  scope="col"
                  class="text-left"
                >
                  Email
                </th>
                <th
                  scope="col"
                  class="text-left"
                >
                  Signed
                </th>
                <th
                  scope="col"
                  class="text-left"
                >
                  PDF
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="walkIn in walkIns"
                :key="walkIn.waiver_id ?? `${walkIn.first}-${walkIn.signed_at}`"
              >
                <td>{{ walkIn.first }}</td>
                <td>{{ walkIn.last }}</td>
                <td>{{ walkIn.email ?? '' }}</td>
                <td>{{ formatDateTime(walkIn.signed_at) }}</td>
                <td>
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
          <p class="text-sm text-muted">
            <b>Count:</b> {{ walkIns.length }}
          </p>
        </template>
      </section>
    </template>
  </div>
</template>
