import { describe, it, expect, vi } from 'vitest';
import { ApiError } from '../client';
import {
  NETWORK_ERROR_MESSAGE,
  authenticate,
  forgotPassword,
  login,
  logout,
  requestEmailSignin,
  resetPassword,
  toFormErrors,
  updatePassword,
  verifyEmailSigninCode,
} from '../auth';
import { jsonResponse, requestBody } from '../../test/helpers';

function stubOnce(response: Response) {
  const fetchMock = vi.fn().mockResolvedValue(response);
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function lastCall(fetchMock: ReturnType<typeof stubOnce>): [string, RequestInit] {
  return fetchMock.mock.calls[0] as [string, RequestInit];
}

describe('auth api endpoints', () => {
  it('authenticate posts an empty JSON object to /login/authenticate', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { user_id: 1 }));
    await authenticate();
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/authenticate');
    expect(init.method).toBe('POST');
    expect(requestBody(init)).toEqual({});
  });

  it('login posts email, password and remember and returns the payload', async () => {
    const payload = { user_id: 1, user_level: 1 };
    const fetchMock = stubOnce(jsonResponse(200, payload));
    await expect(login({ email: 'a@b.test', password: 'pw', remember: true })).resolves.toEqual(payload);
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/login');
    expect(requestBody(init)).toEqual({ email: 'a@b.test', password: 'pw', remember: true });
  });

  it('logout posts to /login/logout', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { message: 'bye' }));
    await expect(logout()).resolves.toEqual({ message: 'bye' });
    expect(lastCall(fetchMock)[0]).toBe('/login/logout');
    expect(lastCall(fetchMock)[1].method).toBe('POST');
  });

  it('updatePassword posts the three password fields', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { message: 'ok' }));
    await updatePassword({ old_password: 'a', new_password: 'bb', verify_password: 'bb' });
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/update-password');
    expect(requestBody(init)).toEqual({ old_password: 'a', new_password: 'bb', verify_password: 'bb' });
  });

  it('forgotPassword posts the email to /login/forgot-password', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { ok: true }));
    await expect(forgotPassword('a@b.test')).resolves.toEqual({ ok: true });
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/forgot-password');
    expect(requestBody(init)).toEqual({ email: 'a@b.test' });
  });

  it('resetPassword posts token, email, password and confirmation', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { user_id: 2 }));
    await resetPassword({ token: 't', email: 'a@b.test', password: 'secret1', password_confirmation: 'secret1' });
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/reset-password');
    expect(requestBody(init)).toEqual({ token: 't', email: 'a@b.test', password: 'secret1', password_confirmation: 'secret1' });
  });

  it('requestEmailSignin posts the email and includes redirect_path only when given', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { ok: true }));
    await requestEmailSignin({ email: 'a@b.test' });
    expect(requestBody(lastCall(fetchMock)[1])).toEqual({ email: 'a@b.test' });

    const withRedirect = stubOnce(jsonResponse(200, { ok: true }));
    await requestEmailSignin({ email: 'a@b.test', redirect_path: '/waiver/3' });
    const [url, init] = lastCall(withRedirect);
    expect(url).toBe('/login/email-signin/request');
    expect(requestBody(init)).toEqual({ email: 'a@b.test', redirect_path: '/waiver/3' });
  });

  it('verifyEmailSigninCode posts email and code and keeps redirect_path in the payload', async () => {
    const fetchMock = stubOnce(jsonResponse(200, { user_id: 4, redirect_path: '/members' }));
    const payload = await verifyEmailSigninCode({ email: 'a@b.test', code: '123456' });
    const [url, init] = lastCall(fetchMock);
    expect(url).toBe('/login/email-signin/verify-code');
    expect(requestBody(init)).toEqual({ email: 'a@b.test', code: '123456' });
    expect(payload.redirect_path).toBe('/members');
  });

  it('propagates ApiError with the status and body', async () => {
    stubOnce(jsonResponse(401, ['Invalid credentials.']));
    const error = await login({ email: 'a@b.test', password: 'x', remember: false }).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(401);
    expect((error as ApiError).body).toEqual(['Invalid credentials.']);
  });
});

describe('toFormErrors', () => {
  const fallback = 'Fallback text.';

  it('maps a Laravel 422 body to field errors and drops the generic message', () => {
    const error = new ApiError(422, {
      message: 'The given data was invalid.',
      errors: { email: ['The email field is required.'], password: ['Too short.', 'Also bad.'] },
    });
    expect(toFormErrors(error, fallback)).toEqual({
      messages: [],
      fields: { email: ['The email field is required.'], password: ['Too short.', 'Also bad.'] },
    });
  });

  it('skips empty and non-string field entries, and accepts a bare string as one message', () => {
    const error = new ApiError(422, { errors: { email: [], token: [42, 'Expired.'], other: 'nope' } });
    expect(toFormErrors(error, fallback)).toEqual({ messages: [], fields: { token: ['Expired.'], other: ['nope'] } });
  });

  it('falls back to the message when a 422 has no usable field errors', () => {
    expect(toFormErrors(new ApiError(422, { message: 'Nope.', errors: {} }), fallback)).toEqual({
      messages: ['Nope.'],
      fields: {},
    });
  });

  it('reads the legacy array body of a 401', () => {
    expect(toFormErrors(new ApiError(401, ['Bad password.', '', 3]), fallback)).toEqual({
      messages: ['Bad password.'],
      fields: {},
    });
  });

  it('reads the message of a 503 body', () => {
    expect(toFormErrors(new ApiError(503, { ok: false, message: 'Email sign-in is off.' }), fallback)).toEqual({
      messages: ['Email sign-in is off.'],
      fields: {},
    });
  });

  it('uses the fallback for an empty 401 body', () => {
    expect(toFormErrors(new ApiError(401, null), fallback)).toEqual({ messages: [fallback], fields: {} });
  });

  it('uses the fallback for a non-JSON text body, so raw HTML is never shown', () => {
    expect(toFormErrors(new ApiError(500, '<html>oops</html>'), fallback)).toEqual({ messages: [fallback], fields: {} });
  });

  it('uses the fallback when the message is not a string', () => {
    expect(toFormErrors(new ApiError(500, { message: { deep: true } }), fallback)).toEqual({
      messages: [fallback],
      fields: {},
    });
  });

  it('reports a network failure (fetch TypeError) as a connection problem', () => {
    expect(toFormErrors(new TypeError('Failed to fetch'), fallback)).toEqual({
      messages: [NETWORK_ERROR_MESSAGE],
      fields: {},
    });
  });

  it('uses the fallback for any other error', () => {
    expect(toFormErrors(new Error('boom'), fallback)).toEqual({ messages: [fallback], fields: {} });
  });
});
