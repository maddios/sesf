import { describe, it, expect } from 'vitest';
import { ADMIN_GROUPS, ADMIN_REGISTRY, findEntry, navGroups, type AdminRegistryEntry } from '../registry';
import adminRoutes from '../../router/routes/admin';

const fake = (key: string, group: AdminRegistryEntry['group'], minLevel: 2 | 3): AdminRegistryEntry => ({
  key,
  label: key,
  group,
  path: key,
  component: () => Promise.resolve({ default: {} }),
  minLevel,
});

describe('admin registry', () => {
  it('has unique keys and paths, known groups and blocks in group order', () => {
    const keys = ADMIN_REGISTRY.map((e) => e.key);
    const paths = ADMIN_REGISTRY.map((e) => e.path);
    expect(new Set(keys).size).toBe(keys.length);
    expect(new Set(paths).size).toBe(paths.length);
    const order = ADMIN_REGISTRY.map((e) => ADMIN_GROUPS.indexOf(e.group));
    expect(order.every((index) => index >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    for (const entry of ADMIN_REGISTRY) {
      expect(entry.path).not.toMatch(/^\//);
    }
  });

  it('groups entries by level in group order', () => {
    const entries = [fake('judging', 'Reports', 2), fake('users', 'Members', 3), fake('cars', 'Members', 3)];
    expect(navGroups(3, entries).map((b) => [b.group, b.entries.map((e) => e.key)])).toEqual([
      ['Members', ['users', 'cars']],
      ['Reports', ['judging']],
    ]);
    expect(navGroups(2, entries).map((b) => b.group)).toEqual(['Reports']);
    expect(navGroups(1, entries)).toEqual([]);
    expect(findEntry('cars', entries)?.label).toBe('cars');
    expect(findEntry(undefined, entries)).toBeUndefined();
  });

  it('registers one child route per entry under /admin (admins only by default)', async () => {
    const parent = adminRoutes[0]!;
    expect(parent.path).toBe('/admin');
    expect(parent.meta).toMatchObject({ minUserLevel: 3, title: 'Admin', layout: 'admin' });
    const children = parent.children ?? [];
    expect(children.map((c) => c.name)).toEqual(['admin', ...ADMIN_REGISTRY.map((e) => `admin-${e.key}`)]);
    const sponsors = children.find((c) => c.name === 'admin-sponsors')!;
    expect(sponsors.meta).toMatchObject({ minUserLevel: 3, adminKey: 'sponsors', title: 'Sponsors | Admin' });
    const loaded = await (findEntry('sponsors')!.component());
    expect(loaded.default).toBeTruthy();
  });
});
