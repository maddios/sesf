import type { Router } from 'vue-router';

declare module 'vue-router' {
  interface RouteMeta {
    /** Page title shown in the browser tab, before the site suffix. Omit for the bare site name (home). */
    title?: string;
  }
}

export const SITE_NAME = 'Southeast Sharkfest';
export const TITLE_SUFFIX = ` | ${SITE_NAME}`;

/** Builds the document title: "Title | Southeast Sharkfest", or just the site name when the title is blank. */
export function formatPageTitle(title?: string | null): string {
  const trimmed = typeof title === 'string' ? title.trim() : '';
  return trimmed === '' ? SITE_NAME : `${trimmed}${TITLE_SUFFIX}`;
}

/**
 * Sets document.title. Pages with data-driven titles (an event or member name) call this once their data loads;
 * the router afterEach hook sets the static route.meta.title on every navigation.
 */
export function setPageTitle(title?: string | null): void {
  document.title = formatPageTitle(title);
}

/** Composable form, for components: `const { setPageTitle } = usePageTitle()`. */
export function usePageTitle() {
  return { setPageTitle, formatPageTitle };
}

/** Wires route.meta.title into document.title after each navigation. */
export function installPageTitle(router: Router): void {
  router.afterEach((to, _from, failure) => {
    if (failure === undefined) {
      setPageTitle(to.meta.title);
    }
  });
}
