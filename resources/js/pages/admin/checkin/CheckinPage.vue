<script setup lang="ts">
/**
 * On-site check-in for one event (legacy CheckinList), laid out like the legacy /admin2 check-in list: event filter,
 * toolbar (add registration, hide checked in, coming soon), search by name, guest or invoice, the registrations table
 * (cards on phones) and the check-in modal. The event is in the hash query (?show_id=).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import EventFilter from '../../../components/admin/EventFilter.vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import CheckinActionsDialog from './components/CheckinActionsDialog.vue';
import CheckinAddDialog from './components/CheckinAddDialog.vue';
import { checkinApi, expectedArrival, isComingSoon, matchesSearch, shirtSummary, type CheckinRow } from './checkin';

const route = useRoute();
const router = useRouter();

const rows = ref<CheckinRow[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const search = ref('');
const hideChecked = ref(false);
const soonOnly = ref(false);
const selectedId = ref<number | null>(null);
const addOpen = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);

const showId = computed<number | null>(() => {
  const raw = Array.isArray(route.query.show_id) ? route.query.show_id[0] : route.query.show_id;
  return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null;
});

const visible = computed(() => {
  const searching = search.value.trim() !== '';
  const now = Date.now();
  return rows.value.filter((row) => {
    if (!matchesSearch(row, search.value)) return false;
    if (hideChecked.value && row.is_checked_in) return false;
    if (soonOnly.value && !searching && !isComingSoon(row, now)) return false;
    return true;
  });
});

const checkedCount = computed(() => rows.value.filter((row) => row.is_checked_in).length);

const selectedRow = computed(() => rows.value.find((row) => row.id === selectedId.value) ?? null);

async function load(): Promise<void> {
  if (showId.value === null) {
    rows.value = [];
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    rows.value = await checkinApi.list(showId.value);
  } catch (error) {
    loadError.value = mapAdminError(error, 'The check-in list could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

function replaceRow(updated: CheckinRow): void {
  rows.value = rows.value.map((row) => (row.id === updated.id ? updated : row));
}

function onShowChange(value: number | null): void {
  void router.replace({ query: { ...route.query, show_id: value === null ? undefined : String(value) } });
}

function onCreated(row: CheckinRow): void {
  rows.value = [...rows.value, row];
}

function open(row: CheckinRow): void {
  selectedId.value = row.id;
}

function clearSearch(): void {
  search.value = '';
  searchInput.value?.focus();
}

watch(showId, () => void load());
onMounted(() => void load());
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      Check-in
    </h1>

    <EventFilter
      :model-value="showId"
      @update:model-value="onShowChange"
    />

    <div class="a2-toolbar">
      <button
        type="button"
        class="a2-btn a2-btn-info"
        :disabled="showId === null"
        @click="addOpen = true"
      >
        Add registration
      </button>
      <label class="a2-check">
        <input
          v-model="hideChecked"
          type="checkbox"
        >
        Hide checked-in
      </label>
      <label class="a2-check">
        <input
          v-model="soonOnly"
          type="checkbox"
        >
        Coming soon (next 6h)
      </label>
      <span
        v-if="showId !== null"
        class="a2-toolbar-count"
        role="status"
      ><b>Showing:</b> {{ visible.length }} · {{ checkedCount }} of {{ rows.length }} checked in</span>
    </div>

    <div class="a2-search">
      <label
        for="checkin-search"
        class="a2-search-label"
      >Narrow by name (primary or guest) or registration number</label>
      <div class="a2-search-field">
        <input
          id="checkin-search"
          ref="searchInput"
          v-model="search"
          type="search"
          autocomplete="off"
          placeholder="Name or reg # (e.g. 42 or #42)…"
          class="a2-input"
        >
        <button
          v-if="search.trim() !== ''"
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
      v-if="showId === null"
      class="a2-well"
    >
      Choose an event to start checking people in.
    </p>
    <p
      v-else-if="loadError"
      role="alert"
      class="a2-alert a2-alert-error"
    >
      {{ loadError }}
    </p>
    <p
      v-else-if="loading && rows.length === 0"
      class="a2-muted"
    >
      Loading registrations…
    </p>
    <p
      v-else-if="visible.length === 0"
      class="a2-muted"
    >
      No registrations match.
    </p>

    <table
      v-else
      class="a2-table a2-cards a2-cards--pair"
      aria-label="Registrations"
    >
      <thead>
        <tr>
          <th scope="col">
            Reg #
          </th>
          <th scope="col">
            Name
          </th>
          <th scope="col">
            Waivers
          </th>
          <th scope="col">
            T-shirts
          </th>
          <th scope="col">
            Paid
          </th>
          <th scope="col">
            Check-in
          </th>
          <th scope="col">
            Action
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in visible"
          :key="row.id"
          :data-registration-id="row.id"
        >
          <td data-label="Reg #">
            {{ row.invoice_id }}
          </td>
          <td
            data-label="Name"
            class="a2-cell-title"
          >
            {{ row.primary_name }}
          </td>
          <td
            data-label="Waivers"
            class="a2-cell-sep"
          >
            <span
              class="a2-label"
              :class="row.party_complete ? 'a2-label-success' : 'a2-label-warning'"
            >{{ row.party_complete ? 'Complete' : 'Incomplete' }}</span>
          </td>
          <td data-label="T-shirts">
            {{ row.shirt_lines.length === 0 ? 'None' : shirtSummary(row.shirt_lines) }}
          </td>
          <td data-label="Paid">
            <span
              class="a2-label"
              :class="row.is_paid ? 'a2-label-success' : 'a2-label-warning'"
            >{{ row.is_paid ? 'Paid' : 'Unpaid' }}</span>
          </td>
          <td data-label="Check-in">
            <template v-if="row.is_checked_in">
              <span class="a2-label a2-label-success">Checked in</span>
              <span
                v-if="row.checked_in_at"
                class="a2-muted a2-small block"
              >{{ new Date(row.checked_in_at).toLocaleString() }}</span>
            </template>
            <template v-else>
              <span class="a2-label">Not checked in</span>
              <span
                v-if="expectedArrival(row)"
                class="a2-muted a2-small block"
              >Expected: {{ expectedArrival(row) }}</span>
            </template>
          </td>
          <td class="a2-cell-nolabel">
            <button
              type="button"
              class="a2-btn a2-btn-info a2-btn-block"
              @click="open(row)"
            >
              {{ row.is_checked_in ? 'View / actions' : 'Check-in' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <CheckinActionsDialog
      v-if="selectedRow"
      :row="selectedRow"
      @changed="replaceRow"
      @reload="load"
      @close="selectedId = null"
    />

    <CheckinAddDialog
      v-if="addOpen && showId !== null"
      :show-id="showId"
      @created="onCreated"
      @close="addOpen = false"
    />
  </div>
</template>
