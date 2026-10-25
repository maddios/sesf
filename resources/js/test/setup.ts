import { afterEach, expect, vi } from 'vitest';
import { cleanup } from '@testing-library/vue';
import * as matchers from 'vitest-axe/matchers';
import type {} from 'vitest-axe/extend-expect';

expect.extend(matchers);

// jsdom has no scrolling; the router's scrollBehavior calls window.scrollTo on every navigation.
Object.defineProperty(window, 'scrollTo', { value: () => undefined, writable: true });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
