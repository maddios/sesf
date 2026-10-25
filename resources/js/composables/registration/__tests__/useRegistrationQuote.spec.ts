import { describe, it, expect, vi, afterEach } from 'vitest';
import { computed, effectScope, ref, shallowRef } from 'vue';
import { useRegistrationQuote, QUOTE_DELAY_MS } from '../useRegistrationQuote';
import type { Quote, RegistrationRequest } from '../../../api/registration';

const quoteOf = (total: string): Quote => ({
  cars: '0.00',
  guests: '0.00',
  shirts: '0.00',
  extras: '0.00',
  donation: '0.00',
  total,
  received: '0.00',
  discount: '0.00',
  owed: total,
  lineItems: [],
});

function request(donation: number): RegistrationRequest {
  return {
    registration: { cars: [], guests: [], shirts: [], extras: {}, arrival_date: { data: '' }, arrival_time: '', donation, cancel: false },
  };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('useRegistrationQuote', () => {
  it('waits for the debounce window before asking, and only once for a burst of edits', async () => {
    vi.useFakeTimers();
    const body = shallowRef<RegistrationRequest | null>(request(0));
    const fetchQuote = vi.fn<(body: RegistrationRequest) => Promise<Quote>>(async () => quoteOf('10.00'));
    const scope = effectScope();
    const state = scope.run(() => useRegistrationQuote(computed(() => body.value), fetchQuote))!;
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchQuote).not.toHaveBeenCalled();
    body.value = request(5);
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS - 1);
    body.value = request(10);
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS - 1);
    expect(fetchQuote).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchQuote).toHaveBeenCalledTimes(1);
    expect(fetchQuote.mock.calls[0]?.[0]).toEqual(request(10));
    expect(state.status.value).toBe('ready');
    expect(state.quote.value?.total).toBe('10.00');
    scope.stop();
  });

  it('ignores an old answer that arrives after a newer one', async () => {
    vi.useFakeTimers();
    const body = shallowRef<RegistrationRequest | null>(request(0));
    let resolveFirst: (value: Quote) => void = () => undefined;
    const fetchQuote = vi
      .fn<(body: RegistrationRequest) => Promise<Quote>>()
      .mockImplementationOnce(() => new Promise<Quote>((resolve) => (resolveFirst = resolve)))
      .mockImplementationOnce(async () => quoteOf('new'));
    const scope = effectScope();
    const state = scope.run(() => useRegistrationQuote(computed(() => body.value), fetchQuote))!;
    body.value = request(1);
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS);
    body.value = request(2);
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS);
    resolveFirst(quoteOf('old'));
    await vi.advanceTimersByTimeAsync(0);
    expect(state.quote.value?.total).toBe('new');
    scope.stop();
  });

  it('reports an error and keeps the last good quote', async () => {
    vi.useFakeTimers();
    const body = ref<RegistrationRequest | null>(request(0));
    const fetchQuote = vi.fn().mockRejectedValueOnce(new Error('down'));
    const scope = effectScope();
    const state = scope.run(() => useRegistrationQuote(computed(() => body.value), fetchQuote))!;
    body.value = request(1);
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS);
    expect(state.status.value).toBe('error');
    expect(state.quote.value).toBeNull();
    scope.stop();
  });

  it('does nothing while the body is null and can be refreshed manually', async () => {
    vi.useFakeTimers();
    const body = shallowRef<RegistrationRequest | null>(null);
    const fetchQuote = vi.fn(async () => quoteOf('5.00'));
    const scope = effectScope();
    const state = scope.run(() => useRegistrationQuote(computed(() => body.value), fetchQuote))!;
    body.value = null;
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS * 2);
    expect(fetchQuote).not.toHaveBeenCalled();
    body.value = request(0);
    state.refresh();
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS);
    expect(fetchQuote).toHaveBeenCalledTimes(1);
    scope.stop();
  });

  it('cancels a pending request when its scope is disposed', async () => {
    vi.useFakeTimers();
    const body = shallowRef<RegistrationRequest | null>(request(0));
    const fetchQuote = vi.fn(async () => quoteOf('5.00'));
    const scope = effectScope();
    scope.run(() => useRegistrationQuote(computed(() => body.value), fetchQuote));
    body.value = request(1);
    await vi.advanceTimersByTimeAsync(0);
    scope.stop();
    await vi.advanceTimersByTimeAsync(QUOTE_DELAY_MS * 2);
    expect(fetchQuote).not.toHaveBeenCalled();
  });
});
