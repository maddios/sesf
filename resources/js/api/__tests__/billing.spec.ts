import { describe, it, expect } from 'vitest';
import { fetchInvoice, fetchInvoiceList, InvalidInvoiceError, money, parseInvoice } from '../billing';
import { ApiError } from '../client';
import { invoiceBody, INVOICE_LIST } from '../../test/billing';
import { jsonResponse, stubFetch, callsTo } from '../../test/helpers';

describe('money()', () => {
  it('keeps valid money strings and rejects everything else', () => {
    expect(money('12.5')).toBe('12.5');
    expect(money('-3.00')).toBe('-3.00');
    expect(money(1000)).toBe('1000.00');
    expect(money('1,000')).toBe('0.00');
    expect(money(Number.NaN)).toBe('0.00');
    expect(money(undefined)).toBe('0.00');
  });
});

describe('parseInvoice', () => {
  it('maps the body to camelCase fields', () => {
    const invoice = parseInvoice(invoiceBody());
    expect(invoice).toMatchObject({
      id: 512,
      invoiceId: 1042,
      isPaid: false,
      isCancelled: false,
      donation: 5,
      discount: '10.00',
      discountNote: 'Early bird',
      amountOwed: '1000.00',
      amountReceived: '100.00',
      carShow: { id: 3, name: 'Southeast Sharkfest 2026', profileURL: '#/SoutheastSharkfest/e3', regOpen: true },
      cars: [{ id: 1, name: 'Red Bug', judgingType: 2 }],
      guests: [{ id: 4, fullName: 'Guest Person', type: 'adult' }],
      shirts: [{ id: 9, type: { id: 2, name: 'Large' }, price: '20.00' }],
      extras: [{ showExtraId: 6, numExtras: 2, showExtra: { id: 6, name: 'Dinner', price: '30.00' } }],
    });
    expect(invoice.totals.lineItems).toHaveLength(5);
    expect(invoice.totals.owed).toBe('1000.00');
  });

  it('defaults missing optional parts and keeps a credit', () => {
    const invoice = parseInvoice(
      invoiceBody({ car_show: null, discount_note: undefined, cars: undefined, is_cancelled: true }, { owed: '-4.00', lineItems: undefined }),
    );
    expect(invoice.carShow).toBeNull();
    expect(invoice.discountNote).toBe('');
    expect(invoice.cars).toEqual([]);
    expect(invoice.isCancelled).toBe(true);
    expect(invoice.totals.owed).toBe('-4.00');
    expect(invoice.totals.lineItems).toEqual([]);
  });

  it('rejects a body that is not an invoice', () => {
    expect(() => parseInvoice(null)).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice([])).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), id: 0 })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), totals: null })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), cars: ['not an object'] })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), guests: [null] })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), shirts: [5] })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), extras: [false] })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), car_show: 'x' })).toThrow(InvalidInvoiceError);
    expect(() => parseInvoice({ ...invoiceBody(), totals: { ...invoiceBody().totals, lineItems: [1] } })).toThrow(
      InvalidInvoiceError,
    );
  });

  it('reads a judging type of null and a non-numeric one as null', () => {
    const body = invoiceBody({ cars: [{ id: 1, name: 'A', judging_type: null }, { id: 2, name: 'B', judging_type: 'x' }] });
    expect(parseInvoice(body).cars).toEqual([
      { id: 1, name: 'A', judgingType: null },
      { id: 2, name: 'B', judgingType: null },
    ]);
  });

  it('turns non-string text into empty strings and a null shirt type into an empty type', () => {
    const body = invoiceBody({ car_show: { id: 3, name: 7, profileURL: null, regOpen: 'yes' }, shirts: [{ id: 1, price: 5 }] });
    const invoice = parseInvoice(body);
    expect(invoice.carShow).toEqual({ id: 3, name: '7', profileURL: '', regOpen: false });
    expect(invoice.shirts).toEqual([{ id: 1, type: { id: 0, name: '' }, price: '5.00' }]);
  });
});

describe('fetch helpers', () => {
  it('loads the invoice list for a member', async () => {
    const fetchMock = stubFetch({ 'GET /member/invoice-list?user_id=7': () => jsonResponse(200, INVOICE_LIST) });
    expect(await fetchInvoiceList(7)).toEqual(INVOICE_LIST);
    expect(callsTo(fetchMock, 'GET /member/invoice-list?user_id=7')).toHaveLength(1);
  });

  it('treats a non-array list as empty and rejects a non-object item', async () => {
    stubFetch({ 'GET /member/invoice-list?user_id=7': () => jsonResponse(200, { nope: true }) });
    expect(await fetchInvoiceList(7)).toEqual([]);
    stubFetch({ 'GET /member/invoice-list?user_id=7': () => jsonResponse(200, [1]) });
    await expect(fetchInvoiceList(7)).rejects.toBeInstanceOf(InvalidInvoiceError);
  });

  it('loads one invoice and surfaces a 404 as ApiError', async () => {
    stubFetch({ 'GET /member/invoice?registration_id=512': () => jsonResponse(200, invoiceBody()) });
    expect((await fetchInvoice(512)).id).toBe(512);
    stubFetch({ 'GET /member/invoice?registration_id=9': () => jsonResponse(404, { message: 'Not found.' }) });
    const error = await fetchInvoice(9).catch((failure: unknown) => failure);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(404);
  });
});
