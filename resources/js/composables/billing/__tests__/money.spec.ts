import { describe, it, expect } from 'vitest';
import { formatCredit, formatMoney, isNegativeMoney, isPositiveMoney, paypalAmount } from '../money';
import { buildPaypalFields, PAYPAL_WEBSCR_URL } from '../paypal';
import { invoiceStatus, isPayable, registerPath } from '../invoice';
import { invoiceBody } from '../../../test/billing';
import { parseInvoice } from '../../../api/billing';

describe('formatMoney', () => {
  it('groups thousands and keeps two places', () => {
    expect(formatMoney('1000.00')).toBe('$1,000.00');
    expect(formatMoney('1234567.5')).toBe('$1,234,567.50');
    expect(formatMoney('12.5')).toBe('$12.50');
    expect(formatMoney('0.00')).toBe('$0.00');
  });

  it('shows a minus sign for negative amounts', () => {
    expect(formatMoney('-3.00')).toBe('-$3.00');
  });

  it('shows an em dash for anything that is not a money string', () => {
    expect(formatMoney('abc')).toBe('—');
    expect(formatMoney('1.234')).toBe('—');
  });

  it('formats a credit without its minus sign', () => {
    expect(formatCredit('-1500.25')).toBe('$1,500.25');
  });
});

describe('sign helpers', () => {
  it('detects positive and negative amounts', () => {
    expect(isPositiveMoney('0.01')).toBe(true);
    expect(isPositiveMoney('0.00')).toBe(false);
    expect(isPositiveMoney('-1.00')).toBe(false);
    expect(isPositiveMoney('nope')).toBe(false);
    expect(isNegativeMoney('-0.01')).toBe(true);
    expect(isNegativeMoney('-0.00')).toBe(false);
    expect(isNegativeMoney('1.00')).toBe(false);
  });
});

describe('paypalAmount', () => {
  it('sends a plain two-decimal number with no separators, so 1000 stays 1000.00', () => {
    expect(paypalAmount('1000.00')).toBe('1000.00');
    expect(paypalAmount('1234567.00')).toBe('1234567.00');
    expect(paypalAmount('1000')).toBe('1000.00');
    expect(paypalAmount('1000.5')).toBe('1000.50');
  });

  it('is empty for zero, credits and garbage', () => {
    expect(paypalAmount('0.00')).toBe('');
    expect(paypalAmount('-5.00')).toBe('');
    expect(paypalAmount('12,00')).toBe('');
    expect(paypalAmount('')).toBe('');
  });
});

describe('buildPaypalFields', () => {
  const base = {
    email: 'pay@example.test',
    appUrl: 'https://sesf.example.test',
    registrationId: 512,
    owed: '1000.00',
    returnUrl: 'https://sesf.example.test/#/Fan/u7/billing/512',
  };

  it('builds the xclick fields with notify_url on this app', () => {
    expect(buildPaypalFields(base)).toEqual({
      cmd: '_xclick',
      business: 'pay@example.test',
      item_name: 'Registration fee',
      item_number: '512',
      amount: '1000.00',
      currency_code: 'USD',
      notify_url: 'https://sesf.example.test/paypal-ipn',
      return: 'https://sesf.example.test/#/Fan/u7/billing/512',
      no_shipping: '1',
    });
  });

  it('posts to the PayPal webscr endpoint', () => {
    expect(PAYPAL_WEBSCR_URL).toBe('https://www.paypal.com/cgi-bin/webscr');
  });

  it('returns null when there is nothing to pay or a setting is missing', () => {
    expect(buildPaypalFields({ ...base, owed: '0.00' })).toBeNull();
    expect(buildPaypalFields({ ...base, email: '' })).toBeNull();
    expect(buildPaypalFields({ ...base, appUrl: '' })).toBeNull();
    expect(buildPaypalFields({ ...base, registrationId: 0 })).toBeNull();
  });
});

describe('invoice status and links', () => {
  const invoiceWith = (owed: string, extra: Record<string, unknown> = {}) =>
    parseInvoice(invoiceBody(extra, { owed }));

  it('is unpaid for a positive balance, credit for a negative one, paid for zero', () => {
    expect(invoiceStatus(invoiceWith('1000.00'))).toBe('unpaid');
    expect(invoiceStatus(invoiceWith('-3.00'))).toBe('credit');
    expect(invoiceStatus(invoiceWith('0.00'))).toBe('paid');
  });

  it('is cancelled whatever the balance', () => {
    expect(invoiceStatus(invoiceWith('1000.00', { is_cancelled: true }))).toBe('cancelled');
    expect(isPayable(invoiceWith('1000.00', { is_cancelled: true }))).toBe(false);
  });

  it('is payable only for a positive, uncancelled balance', () => {
    expect(isPayable(invoiceWith('1000.00'))).toBe(true);
    expect(isPayable(invoiceWith('0.00'))).toBe(false);
    expect(isPayable(invoiceWith('-2.00'))).toBe(false);
  });

  it('links to the registration form only while registration is open', () => {
    expect(registerPath(invoiceWith('1000.00'))).toBe('/SoutheastSharkfest/e3/register');
    const closed = invoiceBody({ car_show: { id: 3, name: 'X', profileURL: '#/X/e3', regOpen: false } });
    expect(registerPath(parseInvoice(closed))).toBeNull();
    const noShow = invoiceBody({ car_show: null });
    expect(registerPath(parseInvoice(noShow))).toBeNull();
  });

  it('refuses event paths that are not same-app paths', () => {
    const external = invoiceBody({ car_show: { id: 3, name: 'X', profileURL: 'https://evil.example.test/', regOpen: true } });
    expect(registerPath(parseInvoice(external))).toBeNull();
    const protocolRelative = invoiceBody({ car_show: { id: 3, name: 'X', profileURL: '#//evil.example.test', regOpen: true } });
    expect(registerPath(parseInvoice(protocolRelative))).toBeNull();
  });
});
