import { afterEach, expect, vi } from 'vitest';
import { cleanup } from '@testing-library/vue';
import * as matchers from 'vitest-axe/matchers';
import type {} from 'vitest-axe/extend-expect';

expect.extend(matchers);

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
