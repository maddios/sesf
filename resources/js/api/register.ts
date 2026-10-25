import { api } from './client';
import type { SessionPayload } from './auth';

/** Body for POST /member/register. Field names match the backend contract. */
export type RegisterInput = {
  first_name: string;
  last_name: string;
  email: string;
  email_confirmation: string;
  street: string;
  street2: string;
  city: string;
  state: string;
  zip: string;
  phone: string;
  password: string;
  password_confirmation: string;
  accept_privacy_policy: boolean;
  cf_turnstile_token: string;
};

/** On success the server logs the new user in and returns the same payload as the login endpoints. */
export function registerAccount(input: RegisterInput): Promise<SessionPayload> {
  return api.post<SessionPayload>('/member/register', { ...input });
}
