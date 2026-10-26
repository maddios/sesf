import { describe, it, expect } from 'vitest';
import { ApiError } from '../client';
import {
  changePassword,
  deleteAccount,
  fetchAccountInfo,
  fetchCarBodies,
  fetchCarDetail,
  fetchCarTypes,
  fetchGarage,
  fetchLocation,
  fetchPreferences,
  messagesFromBody,
  messagesFromError,
  saveAccountInfo,
  saveCar,
  saveLocation,
  savePreferences,
  sellCar,
  uploadCarImage,
  uploadMemberImage,
} from '../account';
import { callsTo, jsonResponse, requestBody, stubFetch } from '../../test/helpers';

const empty = (status = 200) => new Response('', { status });

describe('messagesFromBody and messagesFromError', () => {
  it('reads strings, lists and field-keyed lists, dropping blanks', () => {
    expect(messagesFromBody('  ')).toEqual([]);
    expect(messagesFromBody(' Oops ')).toEqual(['Oops']);
    expect(messagesFromBody(['A', ' ', 7, 'B'])).toEqual(['A', 'B']);
    expect(messagesFromBody({ first: ['Too long'], zip: ['Bad'] })).toEqual(['Too long', 'Bad']);
    expect(messagesFromBody(42)).toEqual([]);
    expect(messagesFromBody(null)).toEqual([]);
  });

  it('only takes messages from 400 and 422 responses', () => {
    expect(messagesFromError(new ApiError(400, ['Old Password invalid']))).toEqual(['Old Password invalid']);
    expect(messagesFromError(new ApiError(422, ['x']))).toEqual(['x']);
    expect(messagesFromError(new ApiError(500, '<html>boom</html>'))).toEqual([]);
    expect(messagesFromError(new Error('network'))).toEqual([]);
  });
});

describe('member writes', () => {
  it('never sends user_id on writes or on the self-only reads', async () => {
    const fetchMock = stubFetch({
      'POST /member/account-info': () => empty(),
      'POST /member/location': () => empty(),
      'POST /member/private-preferences': () => empty(),
      'GET /member/location': () => jsonResponse(200, {}),
      'GET /member/private-preferences': () => jsonResponse(200, {}),
      'POST /member/sell-car': () => empty(),
    });
    await saveAccountInfo({ first: 'a', last: 'b', email: 'a@b.test', phone: '' });
    await saveLocation({ address1: '', address2: '', city: '', state: '', zip: '' });
    await savePreferences({ allowSystemEmail: false, allowFutureShowNotifications: false, allowPrivateMessage: false });
    await fetchLocation();
    await fetchPreferences();
    await sellCar(3);
    const urls = fetchMock.mock.calls.map(([url]) => String(url));
    expect(urls.some((url) => url.includes('user_id'))).toBe(false);
  });

  it('saves account info without current_password when the email is unchanged', async () => {
    const fetchMock = stubFetch({ 'POST /member/account-info': () => empty() });
    await saveAccountInfo({ first: 'Shark', last: 'Fan', email: 'fan@example.test', phone: '555' });
    expect(callsTo(fetchMock, 'POST /member/account-info')).toEqual([
      { first: 'Shark', last: 'Fan', email: 'fan@example.test', phone: '555' },
    ]);
  });

  it('sends current_password when supplied for an email change', async () => {
    const fetchMock = stubFetch({ 'POST /member/account-info': () => empty() });
    await saveAccountInfo({ first: 'a', last: 'b', email: 'new@example.test', phone: '', currentPassword: 'secret1' });
    expect(callsTo(fetchMock, 'POST /member/account-info')[0]).toMatchObject({ current_password: 'secret1' });
  });

  it('rejects with the 400 message array', async () => {
    stubFetch({
      'POST /member/account-info': () => jsonResponse(400, ['Email already in use']),
    });
    const error = await saveAccountInfo({ first: 'a', last: 'b', email: 'x@y.test', phone: '' }).catch((e: unknown) => e);
    expect(messagesFromError(error)).toEqual(['Email already in use']);
  });

  it('propagates server errors that are not 2xx or 400', async () => {
    stubFetch({ 'POST /member/location': () => new Response('down', { status: 500 }) });
    await expect(saveLocation({ address1: '', address2: '', city: '', state: '', zip: '' })).rejects.toBeInstanceOf(ApiError);
  });

  it('saves location and returns nothing', async () => {
    const fetchMock = stubFetch({ 'POST /member/location': () => jsonResponse(200, [{ id: 7 }]) });
    await expect(saveLocation({ address1: '1 Way', address2: '', city: 'Ames', state: 'IA', zip: '50010' })).resolves.toBeUndefined();
    expect(callsTo(fetchMock, 'POST /member/location')[0]).toMatchObject({ state: 'IA' });
  });

  it('saves preferences as snake_case booleans', async () => {
    const fetchMock = stubFetch({ 'POST /member/private-preferences': () => empty() });
    await savePreferences({ allowSystemEmail: true, allowFutureShowNotifications: false, allowPrivateMessage: true });
    expect(callsTo(fetchMock, 'POST /member/private-preferences')[0]).toEqual({
      allow_system_email: true,
      allow_future_show_notifications: false,
      allow_private_message: true,
    });
  });

  it('changes the password with the three server field names', async () => {
    const fetchMock = stubFetch({ 'POST /login/update-password': () => empty() });
    await changePassword({ oldPassword: 'old-pass', newPassword: 'new-pass', verifyPassword: 'new-pass' });
    expect(callsTo(fetchMock, 'POST /login/update-password')[0]).toEqual({
      old_password: 'old-pass',
      new_password: 'new-pass',
      verify_password: 'new-pass',
    });
  });

  it('deletes the account with the password', async () => {
    const fetchMock = stubFetch({ 'POST /member/delete-account': () => empty() });
    await deleteAccount('pw-123456');
    expect(callsTo(fetchMock, 'POST /member/delete-account')[0]).toEqual({ password: 'pw-123456' });
  });

  it('sells a car by id', async () => {
    const fetchMock = stubFetch({ 'POST /member/sell-car': () => empty() });
    await sellCar(12);
    expect(callsTo(fetchMock, 'POST /member/sell-car')[0]).toEqual({ id: 12 });
  });
});

describe('reads', () => {
  it('reads account info, location and preferences with defaults for missing fields', async () => {
    stubFetch({
      'GET /member/member-info?user_id=7': () => jsonResponse(200, { first: 'Shark', email: 'f@x.test' }),
      'GET /member/location': () => jsonResponse(200, { address1: '1 Way', zip: 50010 }),
      'GET /member/private-preferences': () =>
        jsonResponse(200, { allow_system_email: '1', allow_future_show_notifications: 0, allow_private_message: true }),
    });
    expect(await fetchAccountInfo(7)).toEqual({ first: 'Shark', last: '', email: 'f@x.test', phone: '' });
    expect(await fetchLocation()).toEqual({ address1: '1 Way', address2: '', city: '', state: '', zip: '50010' });
    expect(await fetchPreferences()).toEqual({
      allowSystemEmail: true,
      allowFutureShowNotifications: false,
      allowPrivateMessage: true,
    });
  });

  it('maps body and type lists (type names come from the type key)', async () => {
    stubFetch({
      'GET /car/body': () => jsonResponse(200, [{ id: 1, name: 'Coupe' }, { id: 0, name: 'Bad' }]),
      'GET /car/type': () => jsonResponse(200, [{ id: 1, name: 'Car' }, { id: 3, type: 'Street' }]),
    });
    expect(await fetchCarBodies()).toEqual([{ id: 1, name: 'Coupe' }]);
    // The server sends {id, name}; the legacy {id, type} shape is still accepted.
    expect(await fetchCarTypes()).toEqual([
      { id: 1, name: 'Car' },
      { id: 3, name: 'Street' },
    ]);
  });

  it('marks a car locked when it is registered for a show', async () => {
    stubFetch({
      'GET /car/profile?car_id=5': () =>
        jsonResponse(200, {
          id: 5,
          nickname: 'Red',
          model: 'Bug',
          year: 1971,
          VIN: 'ABC123',
          body: { id: 2, name: 'Coupe' },
          type: { id: 4, name: 'Street' },
          shows: [{ name: 'Fall show' }],
          imageURL: 'images/cars/5.jpg',
        }),
    });
    expect(await fetchCarDetail(5)).toEqual({
      id: 5,
      nickname: 'Red',
      model: 'Bug',
      year: '1971',
      vin: 'ABC123',
      bodyId: 2,
      typeId: 4,
      imageUrl: '/images/cars/5.jpg',
      locked: true,
    });
  });

  it('treats a car without body, type or shows as unlocked with null ids', async () => {
    stubFetch({ 'GET /car/profile?car_id=6': () => jsonResponse(200, { id: 6, shows: [] }) });
    expect(await fetchCarDetail(6)).toMatchObject({ bodyId: null, typeId: null, locked: false, imageUrl: '' });
  });

  it('lists the garage from a bare array or a cars wrapper, skipping invalid rows', async () => {
    stubFetch({
      'GET /member/cars?user_id=7': () => jsonResponse(200, [{ id: 1, nickname: 'A' }, { nickname: 'no id' }]),
    });
    expect((await fetchGarage(7)).map((car) => car.id)).toEqual([1]);

    stubFetch({ 'GET /member/cars?user_id=7': () => jsonResponse(200, { cars: [{ id: 2 }] }) });
    expect((await fetchGarage(7)).map((car) => car.id)).toEqual([2]);

    stubFetch({ 'GET /member/cars?user_id=7': () => jsonResponse(200, { nope: true }) });
    expect(await fetchGarage(7)).toEqual([]);
  });
});

describe('car writes', () => {
  it('creates a car without an id and reads the new id from the response', async () => {
    const fetchMock = stubFetch({ 'PUT /car/profile': () => jsonResponse(200, { id: 44 }) });
    const id = await saveCar({ nickname: 'Red', model: 'Bug', year: '1971', vin: '', bodyId: 2, typeId: 4 });
    expect(id).toBe(44);
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).toEqual({
      nickname: 'Red',
      model: 'Bug',
      year: '1971',
      VIN: '',
      body: { id: 2 },
      type: { id: 4 },
    });
  });

  it('updates a car with its id and falls back to that id on an empty body', async () => {
    const fetchMock = stubFetch({ 'PUT /car/profile': () => empty() });
    const id = await saveCar({ id: 9, nickname: 'Red', model: '', year: '', vin: 'ABCDE', bodyId: 1, typeId: 1 });
    expect(id).toBe(9);
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).toMatchObject({ car_id: 9, VIN: 'ABCDE' });
    // The server ignores `id`; sending only `id` created a duplicate car instead of updating.
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).not.toHaveProperty('id');
  });

  it('falls back to 0 for a create whose response has no id', async () => {
    stubFetch({ 'PUT /car/profile': () => empty() });
    expect(await saveCar({ nickname: 'Red', model: '', year: '', vin: '', bodyId: 1, typeId: 1 })).toBe(0);
  });
});

describe('uploads', () => {
  it('sends the member photo as multipart FormData without a JSON content type', async () => {
    let received: RequestInit | undefined;
    stubFetch({
      'POST /member/profile-image': (init) => {
        received = init;
        return empty();
      },
    });
    await uploadMemberImage(new File(['x'], 'me.png', { type: 'image/png' }));
    expect(received?.body).toBeInstanceOf(FormData);
    expect((received?.body as FormData).get('file')).toBeInstanceOf(File);
    expect((received?.headers as Record<string, string>)['Content-Type']).toBeUndefined();
  });

  it('treats a non-empty 200 body as the error messages', async () => {
    stubFetch({ 'POST /member/profile-image': () => jsonResponse(200, ['Image is too large']) });
    const error = await uploadMemberImage(new File(['x'], 'me.png', { type: 'image/png' })).catch((e: unknown) => e);
    expect(messagesFromError(error)).toEqual(['Image is too large']);
  });

  it('treats a non-empty plain-text 200 body as an error message', async () => {
    stubFetch({ 'POST /member/profile-image': () => new Response('Not allowed', { status: 200 }) });
    const error = await uploadMemberImage(new File(['x'], 'me.png', { type: 'image/png' })).catch((e: unknown) => e);
    expect(messagesFromError(error)).toEqual(['Not allowed']);
  });

  it('sends the car photo with its car_id', async () => {
    const fetchMock = stubFetch({ 'POST /car/profile-image': () => empty() });
    await uploadCarImage(12, new File(['x'], 'car.jpg', { type: 'image/jpeg' }));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect((init.body as FormData).get('car_id')).toBe('12');
  });
});

describe('requestBody helper', () => {
  it('returns undefined for requests without a body', () => {
    expect(requestBody(undefined)).toBeUndefined();
  });
});
