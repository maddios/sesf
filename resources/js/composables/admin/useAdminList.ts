import { computed, onScopeDispose, ref, shallowRef, watch, type Ref } from 'vue';
import { useRoute, useRouter, type LocationQuery, type LocationQueryRaw } from 'vue-router';
import { mapAdminError, type ListMeta, type ListQuery, type ListResponse } from './useAdminResource';

export interface AdminListDefaults {
  sort?: string;
  direction?: 'asc' | 'desc';
  perPage?: number;
}

export interface UseAdminListOptions<T> {
  /** Usually `resource.list`. */
  fetch: (query: ListQuery) => Promise<ListResponse<T>>;
  defaults?: AdminListDefaults;
  /** Filter keys kept in the URL as f.<key>=value (eq filters). Others are ignored. */
  filterKeys?: string[];
  /** Keep page/sort/search/filters in the hash query (default true). Needs the router. */
  syncUrl?: boolean;
}

export interface AdminListState {
  page: number;
  perPage: number;
  sort: string | undefined;
  direction: 'asc' | 'desc';
  search: string;
  filters: Record<string, string>;
}

export const PER_PAGE_OPTIONS = [10, 25, 50, 100] as const;
const DEFAULT_PER_PAGE = 25;
const FILTER_PREFIX = 'f.';

function first(value: LocationQuery[string] | undefined): string | undefined {
  const item = Array.isArray(value) ? value[0] : value;
  return typeof item === 'string' ? item : undefined;
}

function positiveInt(value: string | undefined, fallback: number, max = Number.MAX_SAFE_INTEGER): number {
  if (value === undefined || !/^\d{1,9}$/.test(value)) {
    return fallback;
  }
  const n = Number(value);
  return n >= 1 && n <= max ? n : fallback;
}

/** Reads list state from a route query. Unknown or malformed values fall back to the defaults. */
export function stateFromQuery(query: LocationQuery, defaults: AdminListDefaults = {}, filterKeys: string[] = []): AdminListState {
  const direction = first(query.direction);
  const filters: Record<string, string> = {};
  for (const key of filterKeys) {
    const value = first(query[FILTER_PREFIX + key]);
    if (value !== undefined && value !== '') {
      filters[key] = value;
    }
  }
  return {
    page: positiveInt(first(query.page), 1),
    perPage: positiveInt(first(query.per_page), defaults.perPage ?? DEFAULT_PER_PAGE, 100),
    sort: first(query.sort) || defaults.sort,
    direction: direction === 'asc' || direction === 'desc' ? direction : (defaults.direction ?? 'asc'),
    search: first(query.search) ?? '',
    filters,
  };
}

/** Writes list state into a query, leaving defaults out so URLs stay short. Keys that are not list keys are kept. */
export function stateToQuery(state: AdminListState, base: LocationQuery, defaults: AdminListDefaults = {}, filterKeys: string[] = []): LocationQueryRaw {
  const next: LocationQueryRaw = {};
  for (const [key, value] of Object.entries(base)) {
    const isListKey = ['page', 'per_page', 'sort', 'direction', 'search'].includes(key) || key.startsWith(FILTER_PREFIX);
    if (!isListKey) {
      next[key] = value;
    }
  }
  if (state.page > 1) next.page = String(state.page);
  if (state.perPage !== (defaults.perPage ?? DEFAULT_PER_PAGE)) next.per_page = String(state.perPage);
  if (state.sort !== undefined && state.sort !== defaults.sort) next.sort = state.sort;
  if (state.direction !== (defaults.direction ?? 'asc')) next.direction = state.direction;
  if (state.search !== '') next.search = state.search;
  for (const key of filterKeys) {
    const value = state.filters[key];
    if (value !== undefined && value !== '') {
      next[FILTER_PREFIX + key] = value;
    }
  }
  return next;
}

export function toListQuery(state: AdminListState): ListQuery {
  return {
    page: state.page,
    per_page: state.perPage,
    sort: state.sort,
    direction: state.direction,
    search: state.search === '' ? undefined : state.search,
    filter: { ...state.filters },
  };
}

/**
 * Server-side list state (page, per page, sort, search, eq filters) for AdminDataTable. With syncUrl (default) the
 * state lives in the hash query, so reload/back/share keep the view; every change re-fetches. Responses that
 * arrive out of order are dropped.
 */
export function useAdminList<T>(options: UseAdminListOptions<T>) {
  const defaults = options.defaults ?? {};
  const filterKeys = options.filterKeys ?? [];
  const syncUrl = options.syncUrl ?? true;
  const route = syncUrl ? useRoute() : null;
  const router = syncUrl ? useRouter() : null;

  const local = ref<AdminListState>(stateFromQuery({}, defaults, filterKeys));
  const state = computed<AdminListState>(() => (route !== null ? stateFromQuery(route.query, defaults, filterKeys) : local.value));

  const rows = shallowRef<T[]>([]) as Ref<T[]>;
  const meta = ref<ListMeta>({ page: 1, per_page: defaults.perPage ?? DEFAULT_PER_PAGE, total: 0, last_page: 1 });
  const loading = ref(false);
  const error = ref<string | null>(null);
  let sequence = 0;
  let disposed = false;

  async function load(): Promise<void> {
    const mine = ++sequence;
    loading.value = true;
    error.value = null;
    try {
      const result = await options.fetch(toListQuery(state.value));
      if (mine !== sequence || disposed) return;
      rows.value = result.data;
      meta.value = result.meta;
    } catch (caught) {
      if (mine !== sequence || disposed) return;
      error.value = mapAdminError(caught, 'The list could not be loaded.').message;
    } finally {
      if (mine === sequence) loading.value = false;
    }
  }

  async function update(patch: Partial<AdminListState>): Promise<void> {
    const next = { ...state.value, ...patch };
    if (route !== null && router !== null) {
      await router.replace({ path: route.path, query: stateToQuery(next, route.query, defaults, filterKeys) });
    } else {
      local.value = next;
    }
  }

  function setPage(page: number): Promise<void> {
    return update({ page });
  }

  function setPerPage(perPage: number): Promise<void> {
    return update({ perPage, page: 1 });
  }

  /** Sort by a column; the same column again flips the direction. */
  function setSort(column: string, direction?: 'asc' | 'desc'): Promise<void> {
    const current = state.value;
    const nextDirection = direction ?? (current.sort === column && current.direction === 'asc' ? 'desc' : 'asc');
    return update({ sort: column, direction: nextDirection, page: 1 });
  }

  function setSearch(search: string): Promise<void> {
    return update({ search: search.trim(), page: 1 });
  }

  function setFilter(key: string, value: string | null): Promise<void> {
    const filters = { ...state.value.filters };
    if (value === null || value === '') {
      delete filters[key];
    } else {
      filters[key] = value;
    }
    return update({ filters, page: 1 });
  }

  watch(
    () => JSON.stringify(toListQuery(state.value)),
    () => void load(),
    { immediate: true },
  );

  onScopeDispose(() => {
    disposed = true;
  });

  return { rows, meta, loading, error, state, reload: load, setPage, setPerPage, setSort, setSearch, setFilter };
}

export type AdminList<T> = ReturnType<typeof useAdminList<T>>;
