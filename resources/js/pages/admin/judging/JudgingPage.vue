<script setup lang="ts">
/**
 * Judging (admin and judges). Event and judging-type filter live in the hash query (?show=&type=). Judges see
 * their assigned events; the judge-assignment and results sections are for admins only.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { useAuthStore } from '../../../stores/auth';
import AddJudgingEntry from './AddJudgingEntry.vue';
import EventJudges from './EventJudges.vue';
import JudgingEntriesTable from './JudgingEntriesTable.vue';
import JudgingResults from './JudgingResults.vue';
import {
  drawUrl,
  fetchEntries,
  fetchShows,
  JUDGING_TYPE_FILTERS,
  qrSheetUrl,
  removeEntry,
  type JudgingEntry,
  type JudgingShow,
} from './judging';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();
const { confirm } = useConfirm();

const shows = ref<JudgingShow[]>([]);
const showsError = ref('');
const entries = ref<JudgingEntry[]>([]);
const entriesError = ref('');
const loadingEntries = ref(false);
let entriesSequence = 0;

function queryValue(name: string): string | undefined {
  const value = route.query[name];
  return typeof value === 'string' ? value : undefined;
}

const showId = computed<number | null>(() => {
  const requested = Number(queryValue('show'));
  if (shows.value.some((show) => show.id === requested)) return requested;
  return shows.value[0]?.id ?? null;
});

const selectedShow = computed<JudgingShow | null>(() => shows.value.find((show) => show.id === showId.value) ?? null);

const typeId = computed<number | null>(() => {
  const requested = Number(queryValue('type'));
  return JUDGING_TYPE_FILTERS.some((type) => type.id === requested) ? requested : null;
});

function updateQuery(changes: Record<string, string | null>): void {
  const query = { ...route.query, ...changes };
  for (const [key, value] of Object.entries(query)) {
    if (value === null) delete query[key];
  }
  void router.replace({ query });
}

async function loadShows(): Promise<void> {
  try {
    shows.value = await fetchShows();
  } catch (error) {
    showsError.value = mapAdminError(error, 'The events could not be loaded.').message;
  }
}

async function loadEntries(): Promise<void> {
  if (showId.value === null) return;
  const sequence = ++entriesSequence;
  loadingEntries.value = true;
  entriesError.value = '';
  try {
    const loaded = await fetchEntries(showId.value, typeId.value);
    if (sequence === entriesSequence) entries.value = loaded;
  } catch (error) {
    if (sequence === entriesSequence) entriesError.value = mapAdminError(error, 'The judging entries could not be loaded.').message;
  } finally {
    if (sequence === entriesSequence) loadingEntries.value = false;
  }
}

watch([showId, typeId], () => void loadEntries(), { immediate: true });

void loadShows();

async function remove(entry: JudgingEntry): Promise<void> {
  if (showId.value === null) return;
  const ok = await confirm({
    title: 'Remove from judging',
    message: `Remove car ${entry.invoice_id} (${entry.owner_name}) from judging? Its answers will no longer be shown.`,
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await removeEntry(showId.value, entry.id);
    toast.success(`Car ${entry.invoice_id} removed from judging.`);
    await loadEntries();
  } catch (error) {
    toast.error(mapAdminError(error, 'The car could not be removed.').message);
  }
}
</script>

<template>
  <div class="space-y-6">
    <h1 class="page-title">
      Judging
    </h1>

    <p
      v-if="showsError"
      role="alert"
      class="text-danger"
    >
      {{ showsError }}
    </p>

    <div class="flex flex-wrap items-end gap-3">
      <div class="min-w-64 flex-1 sm:max-w-md">
        <label
          for="judging-show"
          class="block text-sm font-medium text-text"
        >Event</label>
        <select
          id="judging-show"
          class="form-control mt-1"
          :value="showId === null ? '' : String(showId)"
          :disabled="shows.length === 0"
          @change="updateQuery({ show: ($event.target as HTMLSelectElement).value })"
        >
          <option
            v-for="show in shows"
            :key="show.id"
            :value="String(show.id)"
          >
            {{ show.name }}
          </option>
        </select>
      </div>
      <div class="min-w-48">
        <label
          for="judging-type-filter"
          class="block text-sm font-medium text-text"
        >Filter by type</label>
        <select
          id="judging-type-filter"
          class="form-control mt-1"
          :value="typeId === null ? '' : String(typeId)"
          @change="updateQuery({ type: ($event.target as HTMLSelectElement).value || null })"
        >
          <option value="">
            All
          </option>
          <option
            v-for="type in JUDGING_TYPE_FILTERS"
            :key="type.id"
            :value="String(type.id)"
          >
            {{ type.name }}
          </option>
        </select>
      </div>
    </div>

    <template v-if="showId !== null">
      <div class="flex flex-wrap items-center gap-4 text-sm">
        <a
          :href="drawUrl(showId)"
          target="_blank"
          rel="noopener"
          class="text-link underline"
        >Draw winners</a>
        <a
          :href="qrSheetUrl(showId)"
          target="_blank"
          rel="noopener"
          class="text-link underline"
        >QR sheet (PDF)</a>
        <span
          class="text-muted"
          aria-live="polite"
        >
          {{ loadingEntries ? 'Loading cars…' : `${entries.length} ${entries.length === 1 ? 'car' : 'cars'}` }}
        </span>
      </div>

      <p
        v-if="entriesError"
        role="alert"
        class="text-danger"
      >
        {{ entriesError }}
      </p>
      <p
        v-else-if="!loadingEntries && entries.length === 0"
        class="text-muted"
      >
        No cars are in judging for this selection.
      </p>
      <JudgingEntriesTable
        v-else
        :entries="entries"
        @remove="remove"
      />

      <AddJudgingEntry
        :show-id="showId"
        @added="loadEntries"
      />

      <template v-if="auth.isAdmin && selectedShow !== null">
        <EventJudges :show-id="showId" />
        <JudgingResults
          :show="selectedShow"
          @changed="loadShows"
        />
      </template>
    </template>

    <p
      v-else-if="!showsError && shows.length === 0"
      class="text-muted"
    >
      No events are open for judging.
    </p>
  </div>
</template>
