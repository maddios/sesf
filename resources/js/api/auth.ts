import { ApiError, api } from './client';

/** Body returned by the login endpoints on success. Legacy PHP may send ids and levels as strings. */
export type SessionPayload = {
  user_id: number;
  user_level: number;
  type_id: number;
  email: string;
  first_name: string;
  profileURL: string;
  slideOutName: string;
  fullAddress: string;
};

/** POST /login/email-signin/verify-code adds the path to send the user to after sign-in. */
export type EmailSigninVerifyPayload = SessionPayload & { redirect_path?: string | null };

export type LoginInput = { email: string; password: string; remember: boolean };
export type UpdatePasswordInput = { old_password: string; new_password: string; verify_password: string };
export type ResetPasswordInput = { token: string; email: string; password: string; password_confirmation: string };
export type EmailSigninRequestInput = { email: string; redirect_path?: string };
export type EmailSigninVerifyInput = { email: string; code: string };

/**
 * Normalized error for a form. `messages` are form-level text (banner); `fields` are keyed by the
 * request field name (Laravel's 422 `errors` object).
 */
export interface FormErrors {
  messages: string[];
  fields: Record<string, string[]>;
}

export const NETWORK_ERROR_MESSAGE = 'We could not reach the server. Check your connection and try again.';

export function authenticate(): Promise<SessionPayload> {
  return api.post<SessionPayload>('/login/authenticate', {});
}

export function login(input: LoginInput): Promise<SessionPayload> {
  return api.post<SessionPayload>('/login/login', {
    email: input.email,
    password: input.password,
    remember: input.remember,
  });
}

export function logout(): Promise<{ message?: string }> {
  return api.post<{ message?: string }>('/login/logout', {});
}

export function updatePassword(input: UpdatePasswordInput): Promise<{ message?: string }> {
  return api.post<{ message?: string }>('/login/update-password', { ...input });
}

export function forgotPassword(email: string): Promise<{ ok: boolean }> {
  return api.post<{ ok: boolean }>('/login/forgot-password', { email });
}

export function resetPassword(input: ResetPasswordInput): Promise<SessionPayload> {
  return api.post<SessionPayload>('/login/reset-password', { ...input });
}

/** Rejects with ApiError 503 (message in the body) when emailed sign-in is switched off. */
export function requestEmailSignin(input: EmailSigninRequestInput): Promise<{ ok: boolean }> {
  return api.post<{ ok: boolean }>('/login/email-signin/request', { ...input });
}

export function verifyEmailSigninCode(input: EmailSigninVerifyInput): Promise<EmailSigninVerifyPayload> {
  return api.post<EmailSigninVerifyPayload>('/login/email-signin/verify-code', { ...input });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Keeps only non-empty strings from a body that is either a string or an array of strings. */
function stringList(value: unknown): string[] {
  const items = Array.isArray(value) ? value : [value];
  return items.filter((item): item is string => typeof item === 'string' && item.trim() !== '');
}

/**
 * Maps any failure from the auth endpoints to FormErrors. Handles Laravel 422 validation bodies,
 * the legacy `["message"]` 401 bodies, `{message}` 503 bodies, and network failures. Anything else
 * gets `fallback`, so no raw server text is shown.
 */
export function toFormErrors(error: unknown, fallback: string): FormErrors {
  if (!(error instanceof ApiError)) {
    return { messages: [error instanceof TypeError ? NETWORK_ERROR_MESSAGE : fallback], fields: {} };
  }

  const body: unknown = error.body;
  if (error.status === 422 && isRecord(body) && isRecord(body.errors)) {
    const fields: Record<string, string[]> = {};
    for (const [name, value] of Object.entries(body.errors)) {
      const messages = stringList(value);
      if (messages.length > 0) {
        fields[name] = messages;
      }
    }
    if (Object.keys(fields).length > 0) {
      return { messages: [], fields };
    }
  }

  let messages: string[] = [];
  if (Array.isArray(body)) {
    messages = stringList(body);
  } else if (isRecord(body) && typeof body.message === 'string') {
    messages = stringList(body.message);
  }
  return { messages: messages.length > 0 ? messages : [fallback], fields: {} };
}
