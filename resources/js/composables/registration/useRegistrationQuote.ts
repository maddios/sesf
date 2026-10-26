import { onScopeDispose, ref, shallowRef, watch, type ComputedRef } from 'vue';
import type { Quote, RegistrationRequest } from '../../api/registration';

export type QuoteStatus = 'idle' | 'loading' | 'ready' | 'error';

export const QUOTE_DELAY_MS = 400;

/**
 * Live totals: asks the quote endpoint for the current selections, debounced so typing does not send a request per
 * key. Only the newest request may update the result, so a slow old answer cannot overwrite a newer one.
 */
export function useRegistrationQuote(body: ComputedRef<RegistrationRequest | null>, fetchQuote: (body: RegistrationRequest) => Promise<Quote>) {
  const quote = shallowRef<Quote | null>(null);
  const status = ref<QuoteStatus>('idle');
  let timer: ReturnType<typeof setTimeout> | undefined;
  let sequence = 0;

  async function run(request: RegistrationRequest): Promise<void> {
    sequence += 1;
    const current = sequence;
    status.value = 'loading';
    try {
      const result = await fetchQuote(request);
      if (current === sequence) {
        quote.value = result;
        status.value = 'ready';
      }
    } catch {
      if (current === sequence) {
        status.value = 'error';
      }
    }
  }

  function schedule(request: RegistrationRequest | null): void {
    // Any answer still on its way is for an older body: drop it, even during the debounce wait.
    sequence += 1;
    clearTimeout(timer);
    if (request === null) {
      // Incomplete form: no quote is possible, so drop the old one (the summary falls back to the saved totals).
      quote.value = null;
      status.value = 'idle';
      return;
    }
    status.value = 'loading';
    timer = setTimeout(() => void run(request), QUOTE_DELAY_MS);
  }

  watch(
    () => (body.value === null ? null : JSON.stringify(body.value)),
    (key) => schedule(key === null ? null : body.value),
  );

  onScopeDispose(() => {
    sequence += 1;
    clearTimeout(timer);
  });

  return { quote, status, refresh: () => schedule(body.value) };
}
