<script setup lang="ts">
/**
 * On-site check-in for one event (legacy CheckinList), built for a phone at the door: one card per registration with
 * the legacy columns (waivers, T-shirts, paid, check-in, action), search by name, guest or invoice, "hide checked in"
 * and "coming soon", and the check-in modal. The event is in the hash query (?show_id=).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
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

watch(showId, () => void load());
onMounted(() => void load());
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="page-title">
        Check-in
      </h1>
      <BaseButton
        variant="primary"
        size="lg"
        class="min-h-12"
        :disabled="showId === null"
        @click="addOpen = true"
      >
        Add registration
      </BaseButton>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label
          for="checkin-show"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="checkin-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showId"
            placeholder="Choose an event"
            @update:model-value="onShowChange"
          />
        </div>
      </div>
      <div>
        <label
          for="checkin-search"
          class="block text-sm font-medium text-text"
        >Narrow by name (primary or guest) or registration number</label>
        <div class="mt-1 flex gap-2">
          <input
            id="checkin-search"
            v-model="search"
            type="search"
            autocomplete="off"
            placeholder="Name or reg # (e.g. 42 or #42)…"
            class="min-h-12 w-full min-w-0 rounded-md border border-border bg-surface px-3 text-base"
          >
          <button
            v-if="search !== ''"
            type="button"
            class="min-h-12 min-w-12 rounded-md border border-border px-3"
            aria-label="Clear search"
            @click="search = ''"
          >
            ×
          </button>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 text-base">
      <label class="flex min-h-12 items-center gap-2">
        <input
          v-model="hideChecked"
          type="checkbox"
          class="h-6 w-6"
        >
        Hide checked-in
      </label>
      <label class="flex min-h-12 items-center gap-2">
        <input
          v-model="soonOnly"
          type="checkbox"
          class="h-6 w-6"
        >
        Coming soon (next 6h)
      </label>
      <span
        v-if="showId !== null"
        role="status"
      ><strong>Showing:</strong> {{ visible.length }} · {{ rows.filter((row) => row.is_checked_in).length }} of {{ rows.length }} checked in</span>
    </div>

    <p
      v-if="showId === null"
      class="rounded-md border border-border bg-subtle p-4 text-sm"
    >
      Choose an event to start checking people in.
    </p>
    <p
      v-else-if="loadError"
      role="alert"
      class="rounded-md border border-danger-border bg-danger-bg p-4 text-sm"
    >
      {{ loadError }}
    </p>
    <p
      v-else-if="loading && rows.length === 0"
      class="text-sm text-muted"
    >
      Loading registrations…
    </p>
    <p
      v-else-if="visible.length === 0"
      class="rounded-md border border-border bg-subtle p-4 text-sm"
    >
      No registrations match.
    </p>

    <ul
      v-else
      class="space-y-3"
      aria-label="Registrations"
    >
      <li
        v-for="row in visible"
        :key="row.id"
        class="rounded-md border border-border bg-surface p-4"
        :data-registration-id="row.id"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-lg font-semibold">
              {{ row.primary_name }}
            </p>
            <p class="text-sm text-muted">
              Reg #{{ row.invoice_id }}
            </p>
          </div>
          <div class="flex flex-wrap gap-1.5 text-sm">
            <span
              class="rounded-full px-2 py-0.5"
              :class="row.party_complete ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
            >{{ row.party_complete ? 'Waivers complete' : 'Waivers incomplete' }}</span>
            <span
              class="rounded-full px-2 py-0.5"
              :class="row.is_paid ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
            >{{ row.is_paid ? 'Paid' : 'Unpaid' }}</span>
          </div>
        </div>

        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
          <div>
            <dt class="text-muted">
              T-shirts
            </dt>
            <dd>{{ row.shirt_lines.length === 0 ? 'None' : shirtSummary(row.shirt_lines) }}</dd>
          </div>
          <div>
            <dt class="text-muted">
              Check-in
            </dt>
            <dd v-if="row.is_checked_in">
              <span class="rounded-full bg-success-bg px-2 text-success-fg">Checked in</span>
              <span
                v-if="row.checked_in_at"
                class="block text-muted"
              >{{ new Date(row.checked_in_at).toLocaleString() }}</span>
            </dd>
            <dd v-else>
              <span class="rounded-full border border-border px-2">Not checked in</span>
              <span
                v-if="expectedArrival(row)"
                class="block text-muted"
              >Expected: {{ expectedArrival(row) }}</span>
            </dd>
          </div>
        </dl>
        <div class="mt-3 sm:flex sm:justify-end">
          <BaseButton
            :variant="row.is_checked_in ? 'default' : 'primary'"
            size="lg"
            block
            class="min-h-12"
            @click="open(row)"
          >
            {{ row.is_checked_in ? 'View / actions' : 'Check-in' }}
          </BaseButton>
        </div>
      </li>
    </ul>

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
