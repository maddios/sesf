import { describe, it, expect } from 'vitest';
import { registerAccount, type RegisterInput } from '../register';
import { sendReport } from '../contact';
import { jsonResponse, requestBody, stubFetch, SESSION_PAYLOAD, callsTo } from '../../test/helpers';
import { ApiError } from '../client';

const REGISTER_INPUT: RegisterInput = {
  first_name: 'Shark',
  last_name: 'Fan',
  email: 'fan@example.test',
  email_confirmation: 'fan@example.test',
  street: '1 Ocean Way',
  street2: '',
  city: 'Charlotte',
  state: 'NC',
  zip: '28202',
  phone: '123-123-1234',
  password: 'secret1',
  password_confirmation: 'secret1',
  accept_privacy_policy: true,
  cf_turnstile_token: 'token-1',
};

describe('registerAccount', () => {
  it('posts the registration body and returns the login payload', async () => {
    const fetchMock = stubFetch({ 'POST /member/register': () => jsonResponse(200, SESSION_PAYLOAD) });
    await expect(registerAccount(REGISTER_INPUT)).resolves.toEqual(SESSION_PAYLOAD);
    expect(callsTo(fetchMock, 'POST /member/register')).toEqual([REGISTER_INPUT]);
    expect(requestBody(fetchMock.mock.calls[0]?.[1])).toEqual(REGISTER_INPUT);
  });

  it('rejects with the 400 message list', async () => {
    stubFetch({ 'POST /member/register': () => jsonResponse(400, ['Duplicate.']) });
    await expect(registerAccount(REGISTER_INPUT)).rejects.toMatchObject({ status: 400, body: ['Duplicate.'] });
  });
});

describe('sendReport', () => {
  it('treats the empty 200 response as success', async () => {
    const fetchMock = stubFetch({ 'POST /home/report': () => new Response(null, { status: 200 }) });
    await expect(sendReport({ subject: 'Hi', message: 'Body' })).resolves.toBeUndefined();
    expect(callsTo(fetchMock, 'POST /home/report')).toEqual([{ subject: 'Hi', message: 'Body' }]);
  });

  it('treats any other 2xx with an empty body as success', async () => {
    stubFetch({ 'POST /home/report': () => new Response('', { status: 202 }) });
    await expect(sendReport({ subject: 'Hi', message: 'Body' })).resolves.toBeUndefined();
  });

  it('rethrows client errors so the form can show them', async () => {
    stubFetch({ 'POST /home/report': () => jsonResponse(400, ['Message is required.']) });
    const failure = sendReport({ subject: '', message: '' });
    await expect(failure).rejects.toBeInstanceOf(ApiError);
    await expect(failure).rejects.toMatchObject({ status: 400 });
  });

  it('rethrows network failures', async () => {
    stubFetch({ 'POST /home/report': () => Promise.reject(new TypeError('offline')) });
    await expect(sendReport({ subject: 'Hi', message: 'Body' })).rejects.toBeInstanceOf(TypeError);
  });
});
