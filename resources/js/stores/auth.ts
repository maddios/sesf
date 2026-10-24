import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { ApiError, api } from '../api/client';

export interface AuthUser {
  userId: number;
  userLevel: number;
  typeId: number;
  email: string;
  firstName: string;
  profileUrl: string;
  slideOutName: string;
  fullAddress: string;
}

/** Body returned by POST /login/authenticate on success. */
export interface SessionPayload {
  user_id: number;
  user_level: number;
  type_id: number;
  email: string;
  first_name: string;
  profileURL: string;
  slideOutName: string;
  fullAddress: string;
}

export type SessionFetcher = () => Promise<SessionPayload>;

/** Thrown when /login/authenticate returns a body that is not a usable session. The session stays unresolved. */
export class InvalidSessionPayloadError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidSessionPayloadError';
  }
}

const defaultFetcher: SessionFetcher = () => api.post<SessionPayload>('/login/authenticate');

/** Legacy PHP/MySQL often sends integers as strings, so ids and levels are coerced with Number(). */
function toNumber(value: unknown): number | undefined {
  if (typeof value !== 'number' && typeof value !== 'string') {
    return undefined;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : undefined;
}

function toText(value: unknown): string {
  if (typeof value === 'string') {
    return value;
  }
  return typeof value === 'number' ? String(value) : '';
}

/**
 * Validates and maps the login payload. user_id must be a finite positive number (string or number);
 * a missing or unparseable level/type becomes 0 and missing text fields become ''.
 * Throws InvalidSessionPayloadError otherwise.
 */
export function toAuthUser(payload: unknown): AuthUser {
  if (typeof payload !== 'object' || payload === null) {
    throw new InvalidSessionPayloadError('Session payload is not an object');
  }
  const raw = payload as Record<string, unknown>;
  const userId = toNumber(raw.user_id);
  if (userId === undefined || userId <= 0) {
    throw new InvalidSessionPayloadError('Session payload has an invalid user_id');
  }
  return {
    userId,
    userLevel: toNumber(raw.user_level) ?? 0,
    typeId: toNumber(raw.type_id) ?? 0,
    email: toText(raw.email),
    firstName: toText(raw.first_name),
    profileUrl: toText(raw.profileURL),
    slideOutName: toText(raw.slideOutName),
    fullAddress: toText(raw.fullAddress),
  };
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null);
  /** True once the initial session check has completed (logged in or not). */
  const resolved = ref(false);
  /** The single running session check, shared by concurrent callers. */
  let inFlight: Promise<void> | null = null;
  /** Incremented by clear(). A check started under an older generation is stale and its result is dropped. */
  let generation = 0;

  const isLoggedIn = computed(() => user.value !== null);
  const userLevel = computed(() => user.value?.userLevel ?? 0);

  function setUser(next: AuthUser) {
    user.value = next;
    resolved.value = true;
  }

  /**
   * Logs out. While a session check is running, resolved is left alone so a 401
   * arriving mid-check cannot mark the session resolved before the check finishes.
   */
  function clear() {
    generation += 1;
    user.value = null;
    if (inFlight === null) {
      resolved.value = true;
    }
  }

  async function runCheck(fetcher: SessionFetcher): Promise<void> {
    const startedAt = generation;
    try {
      const next = toAuthUser(await fetcher());
      if (startedAt !== generation) {
        // Logged out (clear) while the check was in flight: keep the logged-out state.
        resolved.value = true;
        return;
      }
      setUser(next);
    } catch (error) {
      if (startedAt !== generation) {
        resolved.value = true;
        return;
      }
      if (error instanceof ApiError && error.status === 401) {
        user.value = null;
        resolved.value = true;
        return;
      }
      // Other failures leave the session unresolved so a later call can retry.
      throw error;
    }
  }

  /** Runs a session check; concurrent callers share the same in-flight request. */
  function fetchUser(fetcher: SessionFetcher = defaultFetcher): Promise<void> {
    if (inFlight === null) {
      inFlight = runCheck(fetcher).finally(() => {
        inFlight = null;
      });
    }
    return inFlight;
  }

  /** Resolves the session once; returns immediately when it is already resolved. */
  function ensureResolved(fetcher?: SessionFetcher): Promise<void> {
    if (resolved.value) {
      return Promise.resolve();
    }
    return fetchUser(fetcher);
  }

  return { user, resolved, isLoggedIn, userLevel, setUser, clear, fetchUser, ensureResolved };
});
