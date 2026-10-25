import { render } from '@testing-library/vue';
import type { Component } from 'vue';
import { createTestRouter, SESSION_PAYLOAD } from './helpers';
import { toAuthUser, useAuthStore, USER_TYPE_ADMIN, USER_TYPE_REGULAR } from '../stores/auth';

/** Invoice body as GET member/invoice returns it (ids 512, owner id 7, show "Southeast Sharkfest 2026"). */
export function invoiceBody(overrides: Record<string, unknown> = {}, totalsOverrides: Record<string, unknown> = {}) {
  return {
    id: 512,
    show_id: 3,
    user_id: 7,
    invoice_id: 1042,
    is_paid: false,
    donation: 5,
    discount: '10.00',
    discount_note: 'Early bird',
    amount_owed: '1000.00',
    amount_received: '100.00',
    car_show: {
      id: 3,
      name: 'Southeast Sharkfest 2026',
      profileURL: '#/SoutheastSharkfest/e3',
      regOpen: true,
    },
    cars: [{ id: 1, name: 'Red Bug', judging_type: 2 }],
    guests: [{ id: 4, fullName: 'Guest Person', type: 'adult' }],
    shirts: [{ id: 9, type: { id: 2, name: 'Large' }, price: '20.00' }],
    extras: [{ show_extra_id: 6, num_extras: 2, show_extra: { id: 6, name: 'Dinner', price: '30.00' } }],
    totals: {
      source: 'computed',
      lineItems: [
        { kind: 'car', label: 'Car registration', quantity: 1, unit: '1000.00', amount: '1000.00' },
        { kind: 'guest', label: 'Guest', quantity: 1, unit: '25.00', amount: '25.00' },
        { kind: 'shirt', label: 'Shirt: Large', quantity: 1, unit: '20.00', amount: '20.00' },
        { kind: 'extra', label: 'Dinner', quantity: 2, unit: '30.00', amount: '60.00' },
        { kind: 'donation', label: 'Donation', quantity: 1, unit: '5.00', amount: '5.00' },
      ],
      cars: '1000.00',
      guests: '25.00',
      shirts: '20.00',
      extras: '60.00',
      donation: '5.00',
      total: '1110.00',
      received: '100.00',
      discount: '10.00',
      owed: '1000.00',
      ...totalsOverrides,
    },
    ...overrides,
  };
}

/** Invoice list as GET member/invoice-list returns it, newest first. */
export const INVOICE_LIST = [
  { id: 512, name: 'Southeast Sharkfest 2026' },
  { id: 511, name: 'Southeast Sharkfest 2025' },
];

/** Invoice body with the given balance owed (a negative value is a credit). */
export function invoiceWithOwed(owed: string, overrides: Record<string, unknown> = {}) {
  return invoiceBody(overrides, { owed });
}

/** Renders a billing component with a fresh store and router. The signed-in user is member 7 unless overridden. */
export async function mountBilling(
  component: Component,
  options: { props?: Record<string, unknown>; userId?: number | null; admin?: boolean; path?: string } = {},
) {
  const { router, pinia } = createTestRouter();
  const auth = useAuthStore(pinia);
  const userId = options.userId === undefined ? 7 : options.userId;
  if (userId !== null) {
    auth.setUser(
      toAuthUser({ ...SESSION_PAYLOAD, user_id: userId, user_level: options.admin ? USER_TYPE_ADMIN : USER_TYPE_REGULAR }),
    );
  }
  await router.push(options.path ?? '/');
  await router.isReady();
  const utils = render(component, { props: options.props, global: { plugins: [pinia, router] } });
  return { router, pinia, auth, ...utils };
}

/** Sets the runtime config the PayPal form reads (window.__SESF__ takes precedence over the Blade JSON). */
export function setClientConfig(config: { paypalEmail?: string; appUrl?: string }) {
  window.__SESF__ = { ...config };
}
