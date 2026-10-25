import { onMounted, ref, shallowRef, type Ref, type ShallowRef } from 'vue';

export type AsyncStatus = 'loading' | 'ready' | 'error';

export interface AsyncData<T> {
  data: ShallowRef<T | null>;
  status: Ref<AsyncStatus>;
  error: ShallowRef<unknown>;
  reload: () => Promise<void>;
}

/**
 * Loads data once on mount and exposes loading / ready / error state plus reload().
 * A reload that finishes after a newer one started is ignored.
 */
export function useAsyncData<T>(loader: () => Promise<T>): AsyncData<T> {
  const data = shallowRef<T | null>(null);
  const status = ref<AsyncStatus>('loading');
  const error = shallowRef<unknown>(null);
  let run = 0;

  async function reload(): Promise<void> {
    run += 1;
    const current = run;
    status.value = 'loading';
    error.value = null;
    try {
      const result = await loader();
      if (current === run) {
        data.value = result;
        status.value = 'ready';
      }
    } catch (failure) {
      if (current === run) {
        error.value = failure;
        status.value = 'error';
      }
    }
  }

  onMounted(() => {
    void reload();
  });

  return { data, status, error, reload };
}
