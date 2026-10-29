import { computed, ref, shallowRef, watch, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import type { Page } from '../../api/cars';
import { DEFAULT_PARAMS, paramsFromQuery, queryFromParams, type DirectoryParams } from './directoryQuery';

export type DirectoryStatus = 'loading' | 'ready' | 'error';

export interface DirectoryLoadParams {
  showId?: number;
  name?: string;
  bodyId?: number;
  page: number;
  limit: number;
}

export interface DirectoryOptions<T> {
  load: (params: DirectoryLoadParams) => Promise<Page<T>>;
  /** Page size: 20 on the directory pages, 25 when embedded in an event tab. */
  limit: number;
  /**
   * True on the full directory pages: filters and page live in the hash query string.
   * False when embedded in an event tab, which keeps its own state so the event URL is not changed.
   */
  syncQuery: boolean;
  /** Fixed show filter (event tabs). Overrides the show_id filter. */
  showId?: number;
}

export interface Directory<T> {
  params: ComputedRef<DirectoryParams>;
  rows: ShallowRef<T[]>;
  total: Ref<number>;
  totalPages: Ref<number>;
  status: Ref<DirectoryStatus>;
  reload: () => Promise<void>;
  /** Applies filter and/or page changes. A filter change without an explicit page goes back to page 1. */
  write: (patch: Partial<DirectoryParams>) => void;
}

const FILTER_KEYS = ['show_id', 'name', 'body_id', 'page'] as const;

function positiveOrUndefined(value: string): number | undefined {
  const n = Number(value);
  return value !== '' && Number.isSafeInteger(n) && n >= 1 ? n : undefined;
}

/**
 * Directory state shared by the member and car lists: loads a page whenever the filters or page change,
 * writes changes back to the hash query (or local state when embedded), and ignores responses that arrive
 * after a newer request started.
 */
export function useDirectory<T>(options: DirectoryOptions<T>): Directory<T> {
  const route = useRoute();
  const router = useRouter();
  const local = ref<DirectoryParams>({ ...DEFAULT_PARAMS });

  const params = computed<DirectoryParams>(() => (options.syncQuery ? paramsFromQuery(route.query) : local.value));
  const rows = shallowRef<T[]>([]);
  const total = ref(0);
  const totalPages = ref(1);
  const status = ref<DirectoryStatus>('loading');
  let run = 0;

  async function fetchCurrent(): Promise<void> {
    run += 1;
    const current = run;
    status.value = 'loading';
    const showId = options.showId ?? positiveOrUndefined(params.value.show_id);
    try {
      const page = await options.load({
        showId,
        name: params.value.name === '' ? undefined : params.value.name,
        bodyId: positiveOrUndefined(params.value.body_id),
        page: params.value.page,
        limit: options.limit,
      });
      if (current !== run) {
        return;
      }
      rows.value = page.data;
      total.value = page.total;
      totalPages.value = Math.max(1, page.last);
      status.value = 'ready';
    } catch {
      if (current === run) {
        rows.value = [];
        status.value = 'error';
      }
    }
  }

  // The key changes exactly when a request-relevant value changes (filters, fixed show, page).
  watch(
    () => JSON.stringify([options.showId ?? params.value.show_id, params.value.name, params.value.body_id, params.value.page]),
    () => {
      void fetchCurrent();
    },
    { immediate: true },
  );

  function write(patch: Partial<DirectoryParams>): void {
    const next: DirectoryParams = { ...params.value, ...patch, page: patch.page ?? 1 };
    if (!options.syncQuery) {
      local.value = next;
      return;
    }
    const query: LocationQueryRaw = { ...route.query };
    for (const key of FILTER_KEYS) {
      delete query[key];
    }
    void router.push({ path: route.path, query: { ...query, ...queryFromParams(next) } });
  }

  return {
    params,
    rows,
    total,
    totalPages,
    status,
    reload: fetchCurrent,
    write,
  };
}
