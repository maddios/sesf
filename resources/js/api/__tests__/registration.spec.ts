import { describe, it, expect, vi, afterEach } from 'vitest';
import { ApiError } from '../client';
import {
  fetchExtra,
  fetchQuote,
  fetchRegistration,
  hasRegistrationErrors,
  parseRegistrationErrors,
  saveExtra,
  saveRegistration,
  toQuote,
  toRegistrationData,
} from '../registration';
import { jsonResponse, requestBody, stubFetch } from '../../test/helpers';
import { registrationRaw, REGISTRATION_URL, QUOTE_URL, SAVE_URL, QUOTE_RAW } from '../../pages/registration/__tests__/registrationFixtures';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('toRegistrationData', () => {
  it('maps the payload to camelCase with normalised ids and money', () => {
    const data = toRegistrationData(registrationRaw());
    expect(data.registration.id).toBe(12);
    expect(data.registration.arrivalDate).toBe('2026-04-30');
    expect(data.registration.cars).toEqual([{ id: 3, judgingType: 21 }]);
    expect(data.registration.guests[0]).toEqual({ id: 5, first: 'Pat', last: 'Guest', email: '', type: 2 });
    expect(data.registration.extras).toEqual([{ showExtraId: 4, numExtras: 1 }]);
    expect(data.cars[0]?.judgingTypes).toEqual([
      { id: 21, name: 'Concours' },
      { id: 22, name: 'Cruise' },
    ]);
    expect(data.shirtTypes[1]).toEqual({ id: 2, name: 'Large', price: '22.00' });
    expect(data.extras[0]).toMatchObject({ id: 4, isEnabled: true, minValue: 0, maxValue: 3, authKey: 'dinner36' });
    expect(data.extras[2]?.isEnabled).toBe(false);
    expect(data.payment.total).toBe('160.00');
    expect(data.targetUser).toBeNull();
  });

  it('accepts a new registration with no id and string-typed ids', () => {
    const data = toRegistrationData({
      registration: { id: null, cars: [{ id: '3', judging_type: '' }], guests: [], shirts: [], extras: [] },
      cars: [],
      extras: [],
    });
    expect(data.registration.id).toBeNull();
    expect(data.registration.cars).toEqual([{ id: 3, judgingType: null }]);
    expect(data.payment.total).toBe('0.00');
  });

  it('reads the admin target user', () => {
    const data = toRegistrationData({ ...registrationRaw(), targetUser: { id: '9', first_name: 'Pat', last_name: 'Member', email: 'pat@example.test' } });
    expect(data.targetUser).toEqual({ id: 9, firstName: 'Pat', lastName: 'Member', email: 'pat@example.test' });
  });

  it('rejects a body that is not an object', () => {
    expect(() => toRegistrationData('nope')).toThrow('not an object');
  });

  it('drops rows without an id and invalid extras', () => {
    const data = toRegistrationData({ registration: { cars: [{ judging_type: 1 }], shirts: [{ id: 'x' }] }, extras: [{ name: 'no id' }] });
    expect(data.registration.cars).toEqual([]);
    expect(data.registration.shirts).toEqual([]);
    expect(data.extras).toEqual([]);
  });

  it('maps the saved shirts with their type name and billed price', () => {
    const data = toRegistrationData({ registration: { shirts: [{ id: '7', type: '12', name: 'Mens S Polo shirt', price: 55 }] } });
    expect(data.registration.shirts).toEqual([{ id: 7, type: 12, name: 'Mens S Polo shirt', price: '55.00' }]);
  });

  it('defaults money and flags safely', () => {
    const data = toRegistrationData({ registration: { donation: 'abc', is_cancelled: '1', extras: { x: { num_extras: 2 } } } });
    expect(data.registration.donation).toBe(0);
    expect(data.registration.isCancelled).toBe(true);
    expect(data.registration.extras).toEqual([]);
  });

  it('accepts snake_case aliases for the reference lists', () => {
    const data = toRegistrationData({ registration: {}, car_bodies: [{ id: 1, name: 'Coupe' }], guest_types: [{ id: 2, name: 'Kid' }], shirt_types: [{ id: 3, name: 'XL', price: 5 }], target_user: { id: 4 } });
    expect(data.carBodies).toEqual([{ id: 1, name: 'Coupe' }]);
    expect(data.guestTypes).toEqual([{ id: 2, name: 'Kid' }]);
    expect(data.shirtTypes).toEqual([{ id: 3, name: 'XL', price: '5.00' }]);
    expect(data.targetUser).toEqual({ id: 4, firstName: '', lastName: '', email: '' });
  });
});

describe('toQuote', () => {
  it('maps totals and line items', () => {
    const quote = toQuote(QUOTE_RAW);
    expect(quote.total).toBe('170.00');
    expect(quote.lineItems).toHaveLength(2);
    expect(quote.lineItems[0]).toEqual({ kind: 'car', label: 'Concours registration', detail: null, quantity: 1, unit: '100.00', amount: '100.00' });
  });

  it('keeps the line detail (which car, guest or shirt type)', () => {
    const quote = toQuote({ payment: { total: '20.00', lineItems: [{ kind: 'shirt', label: 'Shirt', detail: 'Mens XXXL', quantity: 1, unit: '20.00', amount: '20.00' }] } });
    expect(quote.lineItems[0]?.detail).toBe('Mens XXXL');
  });

  it('returns zero totals and no lines when the body is empty', () => {
    expect(toQuote(null)).toMatchObject({ total: '0.00', lineItems: [] });
  });

  it('accepts snake_case line items', () => {
    expect(toQuote({ payment: { line_items: [{ label: 'x', amount: 2 }] } }).lineItems[0]).toMatchObject({ label: 'x', amount: '2.00' });
  });
});

describe('parseRegistrationErrors', () => {
  it('maps section keys to messages, singular guest and shirt keys included', () => {
    const errors = parseRegistrationErrors({
      cars: { response: ['Pick a car.'], status: 400 },
      guest: { '1': { response: ['Email taken.'], status: 400 } },
      shirt: { '0': { response: 'Size is required.', status: 400 } },
      arrival: { response: ['Date closed.'], status: 400 },
      extras: { '4': { response: ['Too many.'], status: 400 } },
      donation: { response: [], status: 400 },
      general: { response: ['Try again.'], status: 400 },
    });
    expect(errors.cars).toEqual(['Pick a car.']);
    expect(errors.guests).toEqual({ 1: ['Email taken.'] });
    expect(errors.shirts).toEqual({ 0: ['Size is required.'] });
    expect(errors.arrival).toEqual(['Date closed.']);
    expect(errors.extras).toEqual({ '4': ['Too many.'] });
    expect(errors.donation).toEqual([]);
    expect(errors.general).toEqual(['Try again.']);
    expect(hasRegistrationErrors(errors)).toBe(true);
  });

  it('returns empty sections for a body that is not an object', () => {
    const errors = parseRegistrationErrors('boom');
    expect(hasRegistrationErrors(errors)).toBe(false);
  });

  it('accepts bare messages, nested objects and plural keys', () => {
    const errors = parseRegistrationErrors({
      cars: 'Bare message',
      donation: ['One', '', { deep: 'Two' }],
      guests: { '0': ['Plural'] },
      shirts: { '2': { response: { field: 'Nested' } } },
      extras: { '5': 'Flat', '6': [] },
      general: '   ',
    });
    expect(errors.cars).toEqual(['Bare message']);
    expect(errors.donation).toEqual(['One', 'Two']);
    expect(errors.guests).toEqual({ 0: ['Plural'] });
    expect(errors.shirts).toEqual({ 2: ['Nested'] });
    expect(errors.extras).toEqual({ '5': ['Flat'] });
    expect(errors.general).toEqual([]);
  });

  it('ignores non-numeric row keys and empty entries', () => {
    const errors = parseRegistrationErrors({ guest: { first: { response: ['x'] }, '3': { response: [] } }, shirt: null });
    expect(errors.guests).toEqual({});
    expect(errors.shirts).toEqual({});
  });
});

describe('request helpers', () => {
  it('loads the registration for the show, with user_id only when given', async () => {
    const fetchMock = stubFetch({ [REGISTRATION_URL]: () => jsonResponse(200, registrationRaw()) });
    await fetchRegistration(36);
    const admin = stubFetch({ 'GET /event/registration?show_id=36&user_id=9': () => jsonResponse(200, registrationRaw()) });
    await fetchRegistration(36, 9);
    expect(fetchMock).toHaveBeenCalledWith('/event/registration?show_id=36', expect.objectContaining({ method: 'GET' }));
    expect(admin).toHaveBeenCalledWith('/event/registration?show_id=36&user_id=9', expect.anything());
  });

  it('posts the body to save and maps the reply', async () => {
    const fetchMock = stubFetch({ [SAVE_URL]: () => jsonResponse(200, registrationRaw()) });
    const data = await saveRegistration(36, { registration: { cars: [], guests: [], shirts: [], extras: {}, arrival_date: { data: '' }, arrival_time: '', donation: 0, cancel: false } });
    expect(data.registration.id).toBe(12);
    expect(requestBody(fetchMock.mock.calls[0]?.[1])).toMatchObject({ registration: { donation: 0, cancel: false } });
  });

  it('rejects a save with an ApiError that carries the 400 body', async () => {
    stubFetch({ [SAVE_URL]: () => jsonResponse(400, { cars: { response: ['x'], status: 400 } }) });
    const failure = await saveRegistration(36, { registration: { cars: [], guests: [], shirts: [], extras: {}, arrival_date: { data: '' }, arrival_time: '', donation: 0, cancel: false } }).catch((e: unknown) => e);
    expect(failure).toBeInstanceOf(ApiError);
    expect(parseRegistrationErrors((failure as ApiError).body).cars).toEqual(['x']);
  });

  it('posts quotes to the quote endpoint with user_id when given', async () => {
    const fetchMock = stubFetch({ [QUOTE_URL]: () => jsonResponse(200, QUOTE_RAW), 'POST /event/registration/quote?show_id=36&user_id=9': () => jsonResponse(200, QUOTE_RAW) });
    const body = { registration: { cars: [], guests: [], shirts: [], extras: {}, arrival_date: { data: '' }, arrival_time: '', donation: 0, cancel: false } };
    expect((await fetchQuote(36, body)).total).toBe('170.00');
    await fetchQuote(36, body, 9);
    expect(fetchMock.mock.calls[1]?.[0]).toBe('/event/registration/quote?show_id=36&user_id=9');
  });

  it('fetches one extra by its auth key and reads the quantity', async () => {
    stubFetch({ 'GET /event/extra?extra_id=dinner36&show_id=36': () => jsonResponse(200, { ...registrationRaw().extras[0], num_extras: 2 }) });
    const detail = await fetchExtra(36, 'dinner36');
    expect(detail.numExtras).toBe(2);
    expect(detail.extra).toMatchObject({ id: 4, maxValue: 3 });
  });

  it('reads the extra from a wrapper object and fails when it has no id', async () => {
    stubFetch({
      'GET /event/extra?extra_id=pin36&show_id=36': () => jsonResponse(200, { extra: registrationRaw().extras[1], num_extras: 1 }),
      'GET /event/extra?extra_id=gone&show_id=36': () => jsonResponse(200, {}),
    });
    expect((await fetchExtra(36, 'pin36')).numExtras).toBe(1);
    await expect(fetchExtra(36, 'gone')).rejects.toMatchObject({ status: 404 });
  });

  it('saves an extra quantity', async () => {
    const fetchMock = stubFetch({ 'POST /event/extra?extra_id=dinner36&show_id=36': () => jsonResponse(200, {}) });
    await saveExtra(36, 'dinner36', 2);
    expect(requestBody(fetchMock.mock.calls[0]?.[1])).toEqual({ num_extras: 2 });
  });
});
