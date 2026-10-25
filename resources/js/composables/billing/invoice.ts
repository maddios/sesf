import type { Invoice } from '../../api/billing';
import { isNegativeMoney, isPositiveMoney } from './money';

/** Invoice status. Cancelled wins; then the balance decides (owed > 0 unpaid, owed < 0 credit, else paid). */
export type InvoiceStatus = 'paid' | 'unpaid' | 'credit' | 'cancelled';

export const STATUS_LABELS: Record<InvoiceStatus, string> = {
  paid: 'Paid',
  unpaid: 'Unpaid',
  credit: 'Credit',
  cancelled: 'Cancelled',
};

export function invoiceStatus(invoice: Invoice): InvoiceStatus {
  if (invoice.isCancelled) {
    return 'cancelled';
  }
  if (isPositiveMoney(invoice.totals.owed)) {
    return 'unpaid';
  }
  if (isNegativeMoney(invoice.totals.owed)) {
    return 'credit';
  }
  return 'paid';
}

/** A cancelled invoice cannot be paid. Only a positive balance is payable. */
export function isPayable(invoice: Invoice): boolean {
  return !invoice.isCancelled && isPositiveMoney(invoice.totals.owed);
}

/**
 * The registration form path for the invoice's event, e.g. "/SharkFest/e12/register". The API gives the event as
 * a hash URL ("#/SharkFest/e12"). Returns null when the event is unknown or its registration is closed.
 */
export function registerPath(invoice: Invoice): string | null {
  const show = invoice.carShow;
  if (show === null || !show.regOpen) {
    return null;
  }
  const path = show.profileURL.replace(/^#/, '');
  if (!/^\/(?!\/)[^\s?#]*$/.test(path)) {
    return null;
  }
  return `${path.replace(/\/+$/, '')}/register`;
}
