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

const defaultFetcher: SessionFetcher = () => api.post<SessionPayload>('/login/authenticate');

export function toAuthUser(payload: SessionPayload): AuthUser {
  return {
    userId: payload.user_id,
    userLevel: payload.user_level,
    typeId: payload.type_id,
    email: payload.email,
    firstName: payload.first_name,
    profileUrl: payload.profileURL,
    slideOutName: payload.slideOutName,
    fullAddress: payload.fullAddress,
  };
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null);
  /** True once the initial session check has completed (logged in or not). */
  const resolved = ref(false);
  /** The single running session check, shared by concurrent callers. */
  let inFlight: Promise<void> | null = null;

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
    user.value = null;
    if (inFlight === null) {
      resolved.value = true;
    }
  }

  async function runCheck(fetcher: SessionFetcher): Promise<void> {
    try {
      setUser(toAuthUser(await fetcher()));
    } catch (error) {
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
