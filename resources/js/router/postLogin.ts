import type { Router } from 'vue-router';
import { getForumUrl } from '../config';

/** Same-origin hash-route paths only. Rejects absolute URLs, protocol-relative '//', backslashes and anything odd. */
export const SAFE_INTERNAL_PATH = /^\/[A-Za-z0-9_/\-:.?=&%]*$/;

/** Paths that legacy served as full page loads, not hash routes. */
const FULL_PAGE_PREFIXES = ['/waiver/', '/judging/', '/judging_answers/', '/judging_draw/'] as const;

export const HOME_PATH = '/';

export type PostLoginTarget = { kind: 'route'; path: string } | { kind: 'page'; url: string };

export function isSafeInternalPath(value: string): boolean {
  return SAFE_INTERNAL_PATH.test(value) && !value.includes('//');
}

/** Returns the first value of a query parameter that may be repeated. */
export function firstQueryString(value: unknown): string | undefined {
  const candidate = Array.isArray(value) ? value[0] : value;
  return typeof candidate === 'string' ? candidate : undefined;
}

/**
 * Turns a post-login `redirect` value into a navigation target, following the legacy rules:
 * 'forum' goes to the forum (full page load); /waiver/, /judging/, /judging_answers/ and /judging_draw/ paths are full page loads;
 * other safe internal paths are hash routes; anything else goes home.
 */
export function resolvePostLoginRedirect(redirect?: string, forumUrl: string = getForumUrl()): PostLoginTarget {
  const home: PostLoginTarget = { kind: 'route', path: HOME_PATH };
  if (typeof redirect !== 'string' || redirect === '') {
    return home;
  }
  if (redirect === 'forum') {
    return forumUrl === '' ? home : { kind: 'page', url: forumUrl };
  }
  if (!isSafeInternalPath(redirect)) {
    return home;
  }
  for (const prefix of FULL_PAGE_PREFIXES) {
    if (redirect.startsWith(prefix) && redirect.length > prefix.length) {
      return { kind: 'page', url: redirect };
    }
  }
  return { kind: 'route', path: redirect };
}

/** Performs the navigation: hash route through the router, or a full page load. */
export async function applyPostLoginRedirect(
  router: Router,
  target: PostLoginTarget,
  navigate: (url: string) => void = (url) => window.location.assign(url),
): Promise<void> {
  if (target.kind === 'page') {
    navigate(target.url);
    return;
  }
  await router.push(target.path);
}
