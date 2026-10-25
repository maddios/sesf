import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { ApiError } from '../api/client';
import {
  authenticate,
  login as loginRequest,
  logout as logoutRequest,
  verifyEmailSigninCode,
  type LoginInput,
  type SessionPayload,
} from '../api/auth';

export type { SessionPayload } from '../api/auth';

/** User levels, matching the legacy User::TYPE_* constants. Levels are ordered: regular < judge < admin. */
export const USER_TYPE_REGULAR = 1;
export const USER_TYPE_JUDGE = 2;
export const USER_TYPE_ADMIN = 3;

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

export type SessionFetcher = () => Promise<SessionPayload>;

/** Thrown when /login/authenticate returns a body that is not a usable session. The session stays unresolved. */
export class InvalidSessionPayloadError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'InvalidSessionPayloadError';
  }
}

const defaultFetcher: SessionFetcher = () => authenticate();

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
  const userId = computed(() => user.value?.userId ?? null);
  /** Judges and admins (legacy nav shows judging for type >= judge). */
  const isJudge = computed(() => userLevel.value >= USER_TYPE_JUDGE);
  const isAdmin = computed(() => userLevel.value === USER_TYPE_ADMIN);
  /** Where to go after sign-in when no ?redirect is given. Set by the route guard, consumed by the login page. */
  const nextUrl = ref<string | null>(null);

  /** Logs in. Bumps the generation like clear(), so a session check started earlier cannot overwrite or null this user. */
  function setUser(next: AuthUser) {
    generation += 1;
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

  /** Stores a logged-in payload (from any login endpoint) and marks the session resolved. */
  function applyPayload(payload: unknown): AuthUser {
    const next = toAuthUser(payload);
    setUser(next);
    return next;
  }

  /** Password sign-in. Rejects with ApiError on bad credentials; the store is left logged out. */
  async function login(input: LoginInput): Promise<AuthUser> {
    return applyPayload(await loginRequest(input));
  }

  /** Signs in with an emailed code. Returns the server's redirect_path when it sends one. */
  async function loginWithEmailCode(email: string, code: string): Promise<string | null> {
    const { redirect_path: redirectPath, ...session } = await verifyEmailSigninCode({ email, code });
    applyPayload(session);
    return typeof redirectPath === 'string' ? redirectPath : null;
  }

  /** Logs out on the server, then clears the local session even if the request failed. */
  async function logout(): Promise<void> {
    try {
      await logoutRequest();
    } finally {
      clear();
    }
  }

  function rememberNextUrl(path: string): void {
    nextUrl.value = path;
  }

  /** Returns the remembered URL and forgets it, so it is used once. */
  function consumeNextUrl(): string | null {
    const value = nextUrl.value;
    nextUrl.value = null;
    return value;
  }

  /** Resolves the session once; returns immediately when it is already resolved. */
  function ensureResolved(fetcher?: SessionFetcher): Promise<void> {
    if (resolved.value) {
      return Promise.resolve();
    }
    return fetchUser(fetcher);
  }

  return {
    user,
    resolved,
    isLoggedIn,
    userId,
    userLevel,
    isJudge,
    isAdmin,
    nextUrl,
    setUser,
    clear,
    fetchUser,
    ensureResolved,
    applyPayload,
    login,
    loginWithEmailCode,
    logout,
    rememberNextUrl,
    consumeNextUrl,
  };
});
