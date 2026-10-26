<script setup lang="ts" generic="T extends object">
/**
 * Server-side data table for admin lists. Pair it with useAdminList (state + URL sync) and bind its refs:
 *   <AdminDataTable :columns :rows="list.rows.value" :meta="list.meta.value" :loading="list.loading.value"
 *     :error="list.error.value" :state="list.state.value" @sort="list.setSort" @page="list.setPage"
 *     @search="list.setSearch" @per-page="list.setPerPage" @retry="list.reload" />
 *
 * - Sortable headers are buttons; the sorted column has aria-sort. Search is debounced (Enter searches now).
 * - Slots: `cell-<key>` ({ row }) for a custom cell, `actions` ({ row }) for row actions, `filters` for the toolbar,
 *   `toolbar-end` for buttons such as "New", `empty` for the no-rows message.
 * - Loading keeps the old rows and sets aria-busy; errors show a retry button; no rows shows the empty state.
 * - Below the md breakpoint rows render as cards (label: value), so nothing scrolls sideways on a phone.
 */
import { computed, onBeforeUnmount, ref, useId, useSlots, watch } from 'vue';
import BasePagination from '../ui/BasePagination.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseButton from '../ui/BaseButton.vue';
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
  }>(),
  {
    loading: false,
    error: null,
    rowKey: 'id',
    searchable: true,
    searchLabel: 'Search',
    debounceMs: 300,
    emptyMessage: 'No records match.',
  },
);

const emit = defineEmits<{
  sort: [column: string];
  page: [page: number];
  search: [text: string];
  perPage: [perPage: number];
  retry: [];
}>();

const slots = useSlots();
const searchId = useId();
const perPageId = useId();
const searchText = ref(props.state.search);
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
</script>

<template>
  <section
    class="space-y-3"
    :aria-label="label"
  >
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
      <form
        v-if="searchable"
        role="search"
        class="min-w-0 sm:w-72"
        @submit.prevent="searchNow"
      >
        <label
          :for="searchId"
          class="block text-sm font-medium text-text"
        >{{ searchLabel }}</label>
        <input
          :id="searchId"
          v-model="searchText"
          type="search"
          class="form-control mt-1 w-full"
          autocomplete="off"
          @input="onSearchInput"
        >
      </form>
      <slot name="filters" />
      <div class="flex gap-2 sm:ml-auto">
        <slot name="toolbar-end" />
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
        class="absolute inset-x-0 top-0 z-10 flex justify-center pt-6"
      >
        <span class="rounded-sm bg-panel px-3 py-2 shadow-card">
          <BaseSpinner label="Loading" />
        </span>
      </div>

      <!-- Desktop table -->
      <div class="hidden overflow-x-auto rounded-sm border border-border md:block">
        <table class="w-full border-collapse text-sm">
          <caption class="sr-only">
            {{ label }}
          </caption>
          <thead class="bg-subtle">
            <tr>
              <th
                v-for="column in columns"
                :key="column.key"
                scope="col"
                class="border-b border-border px-3 py-2 font-semibold text-heading"
                :class="[alignClass[column.align ?? 'left'], column.class ?? '']"
                :aria-sort="ariaSort(column)"
              >
                <button
                  v-if="column.sortable"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-sm font-semibold hover:text-brand"
                  :title="sortHint(column)"
                  @click="emit('sort', column.key)"
                >
                  <span :class="column.srOnlyLabel ? 'sr-only' : ''">{{ column.label }}</span>
                  <span
                    aria-hidden="true"
                    class="text-xs text-muted"
                  >{{ state.sort === column.key ? (state.direction === 'asc' ? '▲' : '▼') : '↕' }}</span>
                </button>
                <span
                  v-else
                  :class="column.srOnlyLabel ? 'sr-only' : ''"
                >{{ column.label }}</span>
              </th>
              <th
                v-if="hasActions"
                scope="col"
                class="border-b border-border px-3 py-2 text-right font-semibold text-heading"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in rows"
              :key="keyOf(row, index)"
              class="border-b border-border last:border-b-0 hover:bg-subtle/60"
            >
              <td
                v-for="column in columns"
                :key="column.key"
                class="px-3 py-2 align-middle"
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
                class="px-3 py-2 text-right whitespace-nowrap"
              >
                <slot
                  name="actions"
                  :row="row"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <ul
        class="space-y-3 md:hidden"
        :aria-label="label"
      >
        <li
          v-for="(row, index) in rows"
          :key="keyOf(row, index)"
          class="rounded-sm border border-border bg-panel p-3 shadow-card"
        >
          <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <template
              v-for="column in mobileColumns"
              :key="column.key"
            >
              <dt class="font-semibold text-muted">
                {{ column.label }}
              </dt>
              <dd class="min-w-0 break-words">
                <slot
                  :name="`cell-${column.key}`"
                  :row="row"
                >
                  {{ cellText(column, row) }}
                </slot>
              </dd>
            </template>
          </dl>
          <div
            v-if="hasActions"
            class="mt-3 flex flex-wrap gap-2"
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
        class="rounded-sm border border-dashed border-border px-4 py-8 text-center text-sm text-muted"
      >
        <slot name="empty">
          {{ emptyMessage }}
        </slot>
      </div>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p
        class="text-sm text-muted"
        aria-live="polite"
      >
        {{ range }}
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <label
          :for="perPageId"
          class="text-sm text-muted"
        >Per page</label>
        <select
          :id="perPageId"
          class="form-control py-1 text-sm"
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
