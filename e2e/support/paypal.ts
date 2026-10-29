import { expect, request } from '@playwright/test';

/** Control API of the PayPal postback stub (docker/e2e/paypalstub/server.mjs). */
export const STUB_URL = process.env.E2E_STUB_URL ?? 'http://localhost:9001';

export type StubMode = 'verified' | 'invalid' | 'down';

export interface StubState {
  mode: StubMode;
  count: number;
  last: { body: string; headers: Record<string, string>; at: string } | null;
}

async function call(method: 'GET' | 'POST', path: string, data?: unknown): Promise<StubState> {
  const ctx = await request.newContext({ baseURL: STUB_URL });
  try {
    const res = await ctx.fetch(path, { method, data });
    expect(res.ok(), `stub ${method} ${path}: ${res.status()}`).toBeTruthy();
    return (await res.json()) as StubState;
  } finally {
    await ctx.dispose();
  }
}

export const stubMode = (mode: StubMode) => call('POST', '/control', { mode });
export const stubReset = () => call('POST', '/reset');
export const stubLast = () => call('GET', '/last');

/**
 * A realistic PayPal IPN body for a Buy Now payment (form-encoded field order as PayPal sends it).
 * Amounts are strings with a '.' decimal, as PayPal posts them.
 */
export function ipnFields(input: {
  registrationId: number;
  gross: string;
  txnId: string;
  receiver: string;
  payerEmail?: string;
  status?: string;
  fee?: string;
}): [string, string][] {
  return [
    ['mc_gross', input.gross],
    ['protection_eligibility', 'Eligible'],
    ['payer_id', 'E2EPAYER0001'],
    ['payment_date', '10:00:00 Oct 25, 2026 PDT'],
    ['payment_status', input.status ?? 'Completed'],
    ['charset', 'UTF-8'],
    ['first_name', 'Pat'],
    ['mc_fee', input.fee ?? '3.20'],
    ['notify_version', '3.9'],
    ['payer_status', 'verified'],
    ['business', input.receiver],
    ['quantity', '1'],
    ['verify_sign', 'AFcWxV21C7fd0v3bYYYRCpSSRl31A-E2E'],
    ['payer_email', input.payerEmail ?? 'buyer@example.test'],
    ['txn_id', input.txnId],
    ['payment_type', 'instant'],
    ['last_name', 'Payer'],
    ['receiver_email', input.receiver],
    ['payment_fee', ''],
    ['receiver_id', 'E2ESELLER001'],
    ['txn_type', 'web_accept'],
    ['item_name', 'Southeast Sharkfest E2E registration'],
    ['mc_currency', 'USD'],
    ['item_number', String(input.registrationId)],
    ['residence_country', 'US'],
    ['handling_amount', '0.00'],
    ['transaction_subject', ''],
    ['payment_gross', input.gross],
    ['shipping', '0.00'],
    ['ipn_track_id', `e2e${input.txnId.toLowerCase()}`],
  ];
}

export function formEncode(fields: [string, string][]): string {
  return fields.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v).replace(/%20/g, '+')}`).join('&');
}

export function uniqueTxn(prefix: string): string {
  return `${prefix}${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`.toUpperCase().slice(0, 17);
}
