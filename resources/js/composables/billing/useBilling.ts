import { ref, shallowRef, watch } from 'vue';
import { ApiError } from '../../api/client';
import { fetchInvoice, fetchInvoiceList, type Invoice, type InvoiceListItem } from '../../api/billing';

export type BillingStatus = 'loading' | 'ready' | 'empty' | 'notFound' | 'error';

export interface BillingRequest {
  memberId: number;
  /** The invoice in the URL. When absent the newest registration is shown (legacy behaviour). */
  registrationId: number | undefined;
}

/**
 * Loads the invoice list and one invoice for the billing page. Only the latest request may update the state, so
 * a slow response for an old invoice cannot overwrite the one the user picked. `load()` reloads the current one.
 */
export function useBilling(request: () => BillingRequest) {
  const status = ref<BillingStatus>('loading');
  const invoices = shallowRef<InvoiceListItem[]>([]);
  const invoice = shallowRef<Invoice | null>(null);
  let run = 0;

  async function load(): Promise<void> {
    run += 1;
    const current = run;
    const { memberId, registrationId } = request();
    status.value = 'loading';
    try {
      const list = await fetchInvoiceList(memberId);
      if (current !== run) {
        return;
      }
      invoices.value = list;
      const id = registrationId ?? list[0]?.id;
      if (id === undefined) {
        invoice.value = null;
        status.value = 'empty';
        return;
      }
      const next = await fetchInvoice(id);
      if (current !== run) {
        return;
      }
      invoice.value = next;
      status.value = 'ready';
    } catch (failure) {
      if (current !== run) {
        return;
      }
      invoice.value = null;
      status.value = failure instanceof ApiError && failure.status === 404 ? 'notFound' : 'error';
    }
  }

  watch(
    () => [request().memberId, request().registrationId] as const,
    () => void load(),
    { immediate: true },
  );

  return { status, invoices, invoice, load };
}
