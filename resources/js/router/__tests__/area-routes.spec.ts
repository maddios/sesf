import { describe, expect, it } from 'vitest';
import { routes } from '../index';

describe('area route modules', () => {
  it('collects routes from router/routes/*.ts and keeps not-found last', () => {
    const paths = routes.map((r) => r.path);
    // Core routes are always present.
    expect(paths).toContain('/login');
    // Area modules (router/routes/*.ts) must be merged in.
    expect(paths.length).toBeGreaterThan(5);
    expect(paths.at(-1)).toBe('/:pathMatch(.*)*');
  });

  it('includes at least one area route when area modules exist', () => {
    const modules = import.meta.glob('../routes/*.ts', { eager: true });
    const expected = Object.values(modules).reduce<number>(
      (total, mod) => total + ((mod as { default?: unknown[] }).default?.length ?? 0),
      0,
    );
    expect(routes.length).toBe(4 + expected + 1);
  });
});
