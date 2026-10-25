import { api } from './client';

/**
 * Member invoices (legacy MemberProfile::getInvoice and getInvoiceList). Both endpoints need a sign-in. Money
 * values arrive as decimal strings with two places ("12.50", "-3.00"); a negative owed amount is a credit.
 * Reads only: nothing here writes an invoice.
 */

export interface InvoiceListItem {
  id: number;
  name: string;
}

export type LineItemKind = 'car' | 'guest' | 'shirt' | 'extra' | 'donation' | (string & {});

export interface InvoiceLineItem {
  kind: LineItemKind;
  label: string;
  quantity: number;
  unit: string;
  amount: string;
}

export type TotalsSource = 'computed' | 'stored' | (string & {});

export interface InvoiceTotals {
  source: TotalsSource;
  lineItems: InvoiceLineItem[];
  cars: string;
  guests: string;
  shirts: string;
  extras: string;
  donation: string;
  total: string;
  received: string;
  discount: string;
  owed: string;
}

export interface InvoiceShow {
  id: number;
  name: string;
  /** Hash path of the event, e.g. "#/SharkFest/e12". Empty when unknown. */
  profileURL: string;
  regOpen: boolean;
}

export interface InvoiceCar {
  id: number;
  name: string;
  judgingType: number | null;
}

export interface InvoiceGuest {
  id: number;
  fullName: string;
  type: string;
}

export interface InvoiceShirt {
  id: number;
  type: { id: number; name: string };
  price: string;
}

export interface InvoiceExtra {
  showExtraId: number;
  numExtras: number;
  showExtra: { id: number; name: string; price: string };
}

export interface Invoice {
  id: number;
  showId: number;
  userId: number;
  invoiceId: number;
  isPaid: boolean;
  isCancelled: boolean;
  donation: number;
  discount: string;
  discountNote: string;
  amountOwed: string;
  amountReceived: string;
  carShow: InvoiceShow | null;
  cars: InvoiceCar[];
  guests: InvoiceGuest[];
  shirts: InvoiceShirt[];
  extras: InvoiceExtra[];
  totals: InvoiceTotals;
}

/** Thrown when a response is not an invoice this page can show. The page treats it as an error. */
export class InvalidInvoiceError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidInvoiceError';
  }
}

type Json = Record<string, unknown>;

function asObject(value: unknown, what: string): Json {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new InvalidInvoiceError(`${what} is not an object`);
  }
  return value as Json;
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function text(value: unknown): string {
  if (typeof value === 'string') {
    return value.trim();
  }
  return typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
}

function num(value: unknown): number {
  const n = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN;
  return Number.isFinite(n) ? n : 0;
}

function optionalNum(value: unknown): number | null {
  if (typeof value !== 'number' && typeof value !== 'string') {
    return null;
  }
  const n = value === '' ? NaN : Number(value);
  return Number.isFinite(n) ? n : null;
}

/** A money string with an optional minus sign and up to two decimals. Anything else becomes "0.00". */
const MONEY_PATTERN = /^-?\d+(\.\d{1,2})?$/;
export function money(value: unknown): string {
  const raw = typeof value === 'number' && Number.isFinite(value) ? value.toFixed(2) : text(value);
  return MONEY_PATTERN.test(raw) ? raw : '0.00';
}

function parseTotals(value: unknown): InvoiceTotals {
  const t = asObject(value, 'totals');
  return {
    source: text(t.source) || 'computed',
    lineItems: asArray(t.lineItems).map((item) => {
      const row = asObject(item, 'line item');
      return {
        kind: text(row.kind),
        label: text(row.label),
        quantity: num(row.quantity),
        unit: money(row.unit),
        amount: money(row.amount),
      };
    }),
    cars: money(t.cars),
    guests: money(t.guests),
    shirts: money(t.shirts),
    extras: money(t.extras),
    donation: money(t.donation),
    total: money(t.total),
    received: money(t.received),
    discount: money(t.discount),
    owed: money(t.owed),
  };
}

function parseShow(value: unknown): InvoiceShow | null {
  if (value === null || value === undefined) {
    return null;
  }
  const s = asObject(value, 'car_show');
  return {
    id: num(s.id),
    name: text(s.name),
    profileURL: text(s.profileURL),
    regOpen: s.regOpen === true,
  };
}

/** Maps the snake_case-free API body (a few keys are camelCase by contract) to the Invoice shape. */
export function parseInvoice(body: unknown): Invoice {
  const r = asObject(body, 'invoice');
  const id = num(r.id);
  if (id <= 0) {
    throw new InvalidInvoiceError('invoice has no id');
  }
  return {
    id,
    showId: num(r.show_id),
    userId: num(r.user_id),
    invoiceId: num(r.invoice_id),
    isPaid: r.is_paid === true,
    isCancelled: r.is_cancelled === true,
    donation: num(r.donation),
    discount: money(r.discount),
    discountNote: text(r.discount_note),
    amountOwed: money(r.amount_owed),
    amountReceived: money(r.amount_received),
    carShow: parseShow(r.car_show),
    cars: asArray(r.cars).map((car) => {
      const c = asObject(car, 'car');
      return { id: num(c.id), name: text(c.name), judgingType: optionalNum(c.judging_type) };
    }),
    guests: asArray(r.guests).map((guest) => {
      const g = asObject(guest, 'guest');
      return { id: num(g.id), fullName: text(g.fullName), type: text(g.type) };
    }),
    shirts: asArray(r.shirts).map((shirt) => {
      const s = asObject(shirt, 'shirt');
      const type = asObject(s.type ?? {}, 'shirt type');
      return {
        id: num(s.id),
        type: { id: num(type.id), name: text(type.name) },
        price: money(s.price),
      };
    }),
    extras: asArray(r.extras).map((extra) => {
      const e = asObject(extra, 'extra');
      const show = asObject(e.show_extra ?? {}, 'show extra');
      return {
        showExtraId: num(e.show_extra_id),
        numExtras: num(e.num_extras),
        showExtra: { id: num(show.id), name: text(show.name), price: money(show.price) },
      };
    }),
    totals: parseTotals(r.totals),
  };
}

export async function fetchInvoiceList(userId: number): Promise<InvoiceListItem[]> {
  const body = await api.get<unknown>(`/member/invoice-list?user_id=${userId}`);
  return asArray(body).map((item) => {
    const row = asObject(item, 'invoice list item');
    return { id: num(row.id), name: text(row.name) };
  });
}

export async function fetchInvoice(registrationId: number): Promise<Invoice> {
  const body = await api.get<unknown>(`/member/invoice?registration_id=${registrationId}`);
  return parseInvoice(body);
}
