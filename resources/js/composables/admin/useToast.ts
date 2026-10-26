import { readonly, ref } from 'vue';

export type ToastVariant = 'success' | 'error' | 'info';

export interface Toast {
  id: number;
  message: string;
  variant: ToastVariant;
}

/** Default time a toast stays up. Errors stay until dismissed. */
export const TOAST_TIMEOUT_MS = 5000;

const toasts = ref<Toast[]>([]);
let nextId = 1;
const timers = new Map<number, ReturnType<typeof setTimeout>>();

function dismiss(id: number): void {
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
  const timer = timers.get(id);
  if (timer !== undefined) {
    clearTimeout(timer);
    timers.delete(id);
  }
}

function show(message: string, variant: ToastVariant = 'success', timeout: number = variant === 'error' ? 0 : TOAST_TIMEOUT_MS): number {
  const id = nextId++;
  toasts.value = [...toasts.value, { id, message, variant }];
  if (timeout > 0) {
    timers.set(
      id,
      setTimeout(() => dismiss(id), timeout),
    );
  }
  return id;
}

function clear(): void {
  [...timers.values()].forEach(clearTimeout);
  timers.clear();
  toasts.value = [];
}

/**
 * App-wide toasts, rendered by AdminToastRegion (a polite live region, so screen readers announce them).
 * `success`/`info` auto-dismiss after 5 s; `error` stays until dismissed.
 */
export function useToast() {
  return {
    toasts: readonly(toasts),
    show,
    success: (message: string) => show(message, 'success'),
    info: (message: string) => show(message, 'info'),
    error: (message: string) => show(message, 'error'),
    dismiss,
    clear,
  };
}
