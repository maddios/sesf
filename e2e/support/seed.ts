import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/** Shape of e2e/.state/seed.json, written by scripts/e2e-seed.php on every seed. */
export interface SeedUser {
  id: number;
  first: string;
  last: string;
  email: string;
  phone: string;
  /** Hash-route path of the profile, e.g. /MiaM/u870. */
  profilePath: string;
}

export interface SeedState {
  appUrl: string;
  password: string;
  paypalEmail: string;
  users: Record<
    'admin' | 'mia' | 'ben' | 'lockout' | 'code' | 'codelock' | 'magic' | 'reset' | 'garage' | 'payer' | 'partial' | 'invalid' | 'a11y',
    SeedUser
  >;
  fillers: SeedUser[];
  cars: Record<'miaAllowed' | 'miaOther' | 'ben', { id: number; name: string }>;
  events: {
    current: { id: number; name: string; path: string; priceCar: number; priceAdult: number; priceChild: number };
    past: { id: number; name: string; path: string };
  };
  extras: Record<'checkbox' | 'numeric', { id: number; name: string; price: number }>;
  registrations: {
    payer: { id: number; owed: string };
    partial: { id: number; owed: string };
    invalid: { id: number; owed: string };
    a11y: { id: number; owed: string };
    miaPast: { id: number };
    benPast: { id: number };
  };
}

let cached: SeedState | null = null;

export function seed(): SeedState {
  if (cached === null) {
    const file = join(process.cwd(), 'e2e', '.state', 'seed.json');
    try {
      cached = JSON.parse(readFileSync(file, 'utf8')) as SeedState;
    } catch {
      throw new Error(`Cannot read ${file}. Start the sandbox with scripts/e2e.sh up (or run scripts/e2e.sh test).`);
    }
  }
  return cached;
}

/** Every seeded email and phone number: none of these may reach an anonymous visitor. */
export function seededPii(): string[] {
  const state = seed();
  const people = [...Object.values(state.users), ...state.fillers];
  return people.flatMap((u) => [u.email, u.phone]).filter((v) => v !== '' && v !== null);
}
