<script setup lang="ts" generic="T extends object">
/**
 * Server-side data table for admin lists. Pair it with useAdminList (state + URL sync) and bind its refs:
 *   <AdminDataTable :columns :rows="list.rows.value" :meta="list.meta.value" :loading="list.loading.value"
 *     :error="list.error.value" :state="list.state.value" @sort="list.setSort" @page="list.setPage"
 *     @search="list.setSearch" @per-page="list.setPerPage" @retry="list.reload" />
 *
 * - Legacy admin look: compact striped rows, gradient header with the sticky header row (the table scrolls inside
 *   its box on desktop, so wide tables scroll sideways there with the first column pinned), sort indicators.
 * - Sortable headers are buttons; the sorted column has aria-sort. Search is debounced (Enter searches now).
 * - Slots: `cell-<key>` ({ row }) for a custom cell, `actions` ({ row }) for row actions (AdminIconButton), `filters`
 *   for the filter bar, `toolbar-end` for buttons such as "New", `empty` for the no-rows message.
 * - Filters: a bar under the search on desktop; on phones a "Filters" button opens them as a bottom sheet
 *   (`activeFilters` shows the count on the button).
 * - `row-click` (optional): a click on a row outside its controls, e.g. to open the edit dialog as the legacy
 *   admin did. Keyboard users use the row's buttons.
 * - Loading keeps the old rows and sets aria-busy; errors show a retry button; no rows shows the empty state.
 * - Below the md breakpoint rows render as cards (label: value), so nothing scrolls sideways on a phone.
 */
import { computed, getCurrentInstance, onBeforeUnmount, ref, useId, useSlots, watch } from 'vue';
import BasePagination from '../ui/BasePagination.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseButton from '../ui/BaseButton.vue';
import AdminIcon from './AdminIcon.vue';
import type { AdminColumn } from './types';
import type { ListMeta } from '../../composables/admin/useAdminResource';
import { PER_PAGE_OPTIONS, type AdminListState } from '../../composables/admin/useAdminList';

const props = withDefaults(
  defineProps<{
    columns: AdminColumn<T>[];
    rows: T[];
    meta: ListMeta;
    state: AdminListState;
    loading?: boolean;
    error?: string | null;
    /** Accessible name of the table, e.g. "Sponsors". */
    label: string;
    rowKey?: string;
    searchable?: boolean;
    searchLabel?: string;
    debounceMs?: number;
    emptyMessage?: string;
    /** Number of filters in use, shown on the phone "Filters" button. */
    activeFilters?: number;
  }>(),
  {
    loading: false,
    error: null,
    rowKey: 'id',
    searchable: true,
    searchLabel: 'Search',
    debounceMs: 300,
    emptyMessage: 'No records match.',
    activeFilters: 0,
  },
);

const emit = defineEmits<{
  sort: [column: string];
  page: [page: number];
  search: [text: string];
  perPage: [perPage: number];
  retry: [];
  rowClick: [row: T];
}>();

const slots = useSlots();
const searchId = useId();
const perPageId = useId();
const searchText = ref(props.state.search);
const filtersOpen = ref(false);
const filtersId = useId();
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
  () => props.state.search,
  (value) => {
    if (value !== searchText.value.trim()) searchText.value = value;
  },
);

function onSearchInput(): void {
  clearTimeout(timer);
  timer = setTimeout(() => emit('search', searchText.value.trim()), props.debounceMs);
}

function searchNow(): void {
  clearTimeout(timer);
  emit('search', searchText.value.trim());
}

onBeforeUnmount(() => clearTimeout(timer));

const hasActions = computed(() => slots.actions !== undefined);
const mobileColumns = computed(() => props.columns.filter((column) => column.hideOnMobile !== true));

function ariaSort(column: AdminColumn<T>): 'ascending' | 'descending' | 'none' | undefined {
  if (column.sortable !== true) return undefined;
  if (props.state.sort !== column.key) return 'none';
  return props.state.direction === 'asc' ? 'ascending' : 'descending';
}

function sortHint(column: AdminColumn<T>): string {
  if (props.state.sort !== column.key) return `Sort by ${column.label}`;
  return props.state.direction === 'asc' ? `Sort by ${column.label}, descending` : `Sort by ${column.label}, ascending`;
}

function cellText(column: AdminColumn<T>, row: T): string {
  if (column.format !== undefined) return column.format(row);
  const value = (row as Record<string, unknown>)[column.key];
  return value === null || value === undefined ? '' : String(value);
}

function keyOf(row: T, index: number): string {
  const value = (row as Record<string, unknown>)[props.rowKey];
  return typeof value === 'string' || typeof value === 'number' ? String(value) : String(index);
}

const range = computed(() => {
  if (props.meta.total === 0) return '0 records';
  const start = (props.meta.page - 1) * props.meta.per_page + 1;
  const end = Math.min(props.meta.total, start + props.rows.length - 1);
  return `${start}–${end} of ${props.meta.total}`;
});

const alignClass = { left: 'text-left', right: 'text-right', center: 'text-center' } as const;

const hasFilters = computed(() => slots.filters !== undefined);
const listens = getCurrentInstance()?.vnode.props?.onRowClick !== undefined;

function onRowClick(event: MouseEvent, row: T): void {
  if (!listens) return;
  if ((event.target as HTMLElement).closest('a, button, input, select, textarea, label, [role="combobox"]')) return;
  emit('rowClick', row);
}
</script>

<template>
  <section
    class="space-y-2"
    :aria-label="label"
  >
    <div class="admin-toolbar">
      <form
        v-if="searchable"
        role="search"
        class="admin-search"
        @submit.prevent="searchNow"
      >
        <label
          :for="searchId"
          class="sr-only"
        >{{ searchLabel }}</label>
        <span
          class="admin-search-icon"
          aria-hidden="true"
        ><AdminIcon name="search" /></span>
        <input
          :id="searchId"
          v-model="searchText"
          type="search"
          class="form-control"
          autocomplete="off"
          :placeholder="searchLabel"
          @input="onSearchInput"
        >
      </form>
      <button
        v-if="hasFilters"
        type="button"
        class="a2-btn md:hidden"
        :aria-expanded="filtersOpen ? 'true' : 'false'"
        :aria-controls="filtersId"
        @click="filtersOpen = !filtersOpen"
      >
        <AdminIcon name="filter" />
        Filters<template v-if="activeFilters > 0">
          ({{ activeFilters }})
        </template>
      </button>
      <div
        v-if="$slots['toolbar-end']"
        class="admin-toolbar-end"
      >
        <slot name="toolbar-end" />
      </div>
    </div>

    <div
      v-if="hasFilters && filtersOpen"
      class="fixed inset-0 z-40 bg-black/40 md:hidden"
      aria-hidden="true"
      @click="filtersOpen = false"
    />
    <div
      v-if="hasFilters"
      :id="filtersId"
      class="admin-filters"
      :class="filtersOpen ? 'is-open' : ''"
      role="group"
      :aria-label="`${label} filters`"
      @keydown.esc="filtersOpen = false"
    >
      <div class="admin-filters-head md:hidden">
        <span class="font-semibold">Filters</span>
        <button
          type="button"
          class="a2-btn a2-btn-primary"
          @click="filtersOpen = false"
        >
          Done
        </button>
      </div>
      <div class="admin-filters-body">
        <slot name="filters" />
      </div>
    </div>

    <BaseAlert
      v-if="error"
      variant="error"
    >
      <p>{{ error }}</p>
      <BaseButton
        size="sm"
        class="mt-2"
        @click="emit('retry')"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <div
      class="relative"
      :aria-busy="loading ? 'true' : 'false'"
    >
      <div
        v-if="loading"
        class="absolute inset-x-0 top-0 z-20 flex justify-center pt-10"
      >
        <span class="rounded-sm bg-panel px-3 py-2 shadow-card">
          <BaseSpinner label="Loading" />
        </span>
      </div>

      <!-- Desktop table -->
      <div class="admin-table-scroll hidden md:block">
        <table class="admin-table">
          <caption class="sr-only">
            {{ label }}
          </caption>
          <thead>
            <tr>
              <th
                v-for="column in columns"
                :key="column.key"
                scope="col"
                :class="[alignClass[column.align ?? 'left'], column.class ?? '', state.sort === column.key ? 'is-sorted' : '']"
                :aria-sort="ariaSort(column)"
              >
                <button
                  v-if="column.sortable"
                  type="button"
                  class="admin-sort"
                  :title="sortHint(column)"
                  @click="emit('sort', column.key)"
                >
                  <span :class="column.srOnlyLabel ? 'sr-only' : ''">{{ column.label }}</span>
                  <span
                    aria-hidden="true"
                    class="admin-sort-mark"
                  >{{ state.sort === column.key ? (state.direction === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                </button>
                <span
                  v-else
                  :class="column.srOnlyLabel ? 'sr-only' : ''"
                >{{ column.label }}</span>
              </th>
              <th
                v-if="hasActions"
                scope="col"
                class="admin-table-actions text-right"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in rows"
              :key="keyOf(row, index)"
              :class="listens ? 'is-clickable' : ''"
              @click="onRowClick($event, row)"
            >
              <td
                v-for="column in columns"
                :key="column.key"
                :class="[alignClass[column.align ?? 'left'], column.class ?? '']"
              >
                <slot
                  :name="`cell-${column.key}`"
                  :row="row"
                >
                  {{ cellText(column, row) }}
                </slot>
              </td>
              <td
                v-if="hasActions"
                class="admin-table-actions"
              >
                <div class="a2-actions">
                  <slot
                    name="actions"
                    :row="row"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <ul
        class="admin-cards md:hidden"
        :aria-label="label"
      >
        <li
          v-for="(row, index) in rows"
          :key="keyOf(row, index)"
          class="admin-card"
        >
          <dl class="admin-card-fields">
            <div
              v-for="(column, columnIndex) in mobileColumns"
              :key="column.key"
              :class="columnIndex === 0 ? 'admin-card-title' : 'admin-card-field'"
            >
              <dt :class="columnIndex === 0 ? 'sr-only' : ''">
                {{ column.label }}
              </dt>
              <dd>
                <slot
                  :name="`cell-${column.key}`"
                  :row="row"
                >
                  {{ cellText(column, row) }}
                </slot>
              </dd>
            </div>
          </dl>
          <div
            v-if="hasActions"
            class="admin-card-actions a2-actions"
          >
            <slot
              name="actions"
              :row="row"
            />
          </div>
        </li>
      </ul>

      <div
        v-if="!loading && !error && rows.length === 0"
        class="admin-empty"
      >
        <slot name="empty">
          {{ emptyMessage }}
        </slot>
      </div>
    </div>

    <div class="admin-pager">
      <p aria-live="polite">
        {{ range }}
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <label
          :for="perPageId"
          class="font-normal"
        >Per page</label>
        <select
          :id="perPageId"
          class="form-control w-auto py-0"
          :value="String(state.perPage)"
          @change="emit('perPage', Number(($event.target as HTMLSelectElement).value))"
        >
          <option
            v-for="option in PER_PAGE_OPTIONS"
            :key="option"
            :value="String(option)"
          >
            {{ option }}
          </option>
        </select>
        <BasePagination
          :page="meta.page"
          :total-pages="meta.last_page"
          :sync-query="false"
          :label="`${label} pages`"
          @update:page="(page) => emit('page', page)"
        />
      </div>
    </div>
  </section>
</template>
