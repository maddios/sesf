<script setup lang="ts">
/**
 * Judging (admin and judges). Event and judging-type filter live in the hash query (?show=&type=). Judges see
 * their assigned events; the judge-assignment and results sections are for admins only.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
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

const showOptions = computed(() => shows.value.map((show) => ({ value: show.id, label: show.name })));
const TYPE_OPTIONS = [{ value: '', label: 'All' }, ...JUDGING_TYPE_FILTERS.map((type) => ({ value: String(type.id), label: type.name }))];

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
  <div class="a2-page">
    <h1 class="sr-only">
      Judging
    </h1>

    <p
      v-if="showsError"
      role="alert"
      class="a2-alert a2-alert-error"
    >
      {{ showsError }}
    </p>

    <template v-if="shows.length > 0">
      <h2 class="a2-legend">
        Filter
      </h2>
      <div class="mb-3 grid gap-3 sm:grid-cols-2 sm:gap-4 md:max-w-3xl">
        <div>
          <label
            for="judging-show"
            class="a2-search-label"
          >Event</label>
          <BaseCombobox
            id="judging-show"
            :model-value="showId"
            :options="showOptions"
            @update:model-value="(value) => value !== null && updateQuery({ show: String(value) })"
          />
        </div>
        <div>
          <label
            for="judging-type-filter"
            class="a2-search-label"
          >Filter by type</label>
          <BaseCombobox
            id="judging-type-filter"
            :model-value="typeId === null ? '' : String(typeId)"
            :options="TYPE_OPTIONS"
            @update:model-value="(value) => updateQuery({ type: value === null || value === '' ? null : String(value) })"
          />
        </div>
      </div>
    </template>

    <template v-if="showId !== null">
      <h2 class="a2-legend">
        Result
      </h2>

      <div class="mb-4">
        <details
          v-if="auth.isAdmin"
          class="a2-accordion"
        >
          <summary>Event judges</summary>
          <div class="a2-accordion-body">
            <EventJudges :show-id="showId" />
          </div>
        </details>
        <details class="a2-accordion">
          <summary>New judging entry</summary>
          <div class="a2-accordion-body">
            <AddJudgingEntry
              :show-id="showId"
              @added="loadEntries"
            />
          </div>
        </details>
        <details
          v-if="auth.isAdmin && selectedShow !== null"
          class="a2-accordion"
        >
          <summary>Results release and emails</summary>
          <div class="a2-accordion-body">
            <JudgingResults
              :show="selectedShow"
              @changed="loadShows"
            />
          </div>
        </details>
      </div>

      <p class="a2-toolbar">
        <b aria-live="polite">{{ loadingEntries ? 'Loading cars…' : `Num Cars: ${entries.length}` }}</b>
        <span class="a2-links">
          <a
            :href="drawUrl(showId)"
            target="_blank"
            rel="noopener"
          >Draw winners</a>
          <a
            :href="qrSheetUrl(showId)"
            target="_blank"
            rel="noopener"
          >QR sheet (PDF)</a>
        </span>
      </p>

      <p
        v-if="entriesError"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ entriesError }}
      </p>
      <p
        v-else-if="!loadingEntries && entries.length === 0"
        class="a2-muted"
      >
        No cars are in judging for this selection.
      </p>
      <JudgingEntriesTable
        v-else
        :entries="entries"
        @remove="remove"
      />
    </template>

    <h2
      v-else-if="!showsError && shows.length === 0"
      class="a2-legend"
    >
      Not a judge for any events
    </h2>
  </div>
</template>
