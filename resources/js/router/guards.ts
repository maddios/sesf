import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router';
import { USER_TYPE_ADMIN, type useAuthStore } from '../stores/auth';

declare module 'vue-router' {
  interface RouteMeta {
    /** Minimum user level. Anonymous users go to /login; lower levels go home. */
    minUserLevel?: number;
    /** Only the member whose id is the :memberId param, or an admin, may view. */
    privateProfileSection?: boolean;
    /** Logged-in users are sent home (login, forgot and reset password). */
    guestOnly?: boolean;
  }
}

/** Paths that are the sign-in flow itself and so are never remembered as the post-login destination. */
export const AUTH_FLOW_PATHS: readonly string[] = ['/login', '/forgot-password', '/reset-password'];

export type AuthStore = ReturnType<typeof useAuthStore>;

export interface AccessState {
  isLoggedIn: boolean;
  userId: number | null;
  userLevel: number;
}

type RouteForAccess = Pick<RouteLocationNormalized, 'meta' | 'params' | 'fullPath'>;

function firstParam(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

/** Pure access rule for a route. Returns the redirect, or undefined when the navigation may proceed. */
export function accessRedirect(route: RouteForAccess, state: AccessState): RouteLocationRaw | undefined {
  const { meta } = route;
  const toLogin = { path: '/login', query: { redirect: route.fullPath } };

  if (meta.guestOnly === true && state.isLoggedIn) {
    return { path: '/' };
  }

  const minUserLevel = meta.minUserLevel ?? 0;
  if (minUserLevel > 0) {
    if (!state.isLoggedIn) {
      return toLogin;
    }
    if (state.userLevel < minUserLevel) {
      return { path: '/' };
    }
  }

  if (meta.privateProfileSection === true) {
    if (!state.isLoggedIn) {
      return toLogin;
    }
    const memberId = firstParam(route.params.memberId as string | string[] | undefined);
    const isOwner = state.userId !== null && memberId === String(state.userId);
    const isAdmin = state.userLevel === USER_TYPE_ADMIN;
    if (!isOwner && !isAdmin) {
      return { path: '/' };
    }
  }

  return undefined;
}

/**
 * Global guard: waits for the session check, remembers where an anonymous user was headed,
 * then applies accessRedirect. A failed session check is treated as anonymous and retried on the next navigation.
 */
export function installAuthGuard(router: Router, getAuth: () => AuthStore): void {
  router.beforeEach(async (to) => {
    const auth = getAuth();
    try {
      await auth.ensureResolved();
    } catch {
      // Session check failed (e.g. offline): stay anonymous. The next navigation retries the check.
    }
    if (!AUTH_FLOW_PATHS.includes(to.path)) {
      auth.rememberNextUrl(to.fullPath);
    }
    return accessRedirect(to, {
      isLoggedIn: auth.isLoggedIn,
      userId: auth.userId,
      userLevel: auth.userLevel,
    }) ?? true;
  });
}
