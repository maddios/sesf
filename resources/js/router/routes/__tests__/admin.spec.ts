import { describe, it, expect } from 'vitest';
import { createTestRouter } from '../../../test/helpers';
import { ADMIN_SESSION, stubAdminFetch } from '../../../test/admin';
import { ADMIN_REGISTRY } from '../../../admin/registry';

const JUDGE_SESSION = { ...ADMIN_SESSION, user_level: 2, type_id: 2, first_name: 'Jude' };

async function visit(path: string, session: unknown) {
  stubAdminFetch(() => undefined, session);
  const { router } = createTestRouter();
  await router.push(path);
  await router.isReady();
  return router.currentRoute.value;
}

describe('admin routes and judges', () => {
  it('sends a judge from the admin dashboard to the judging page', async () => {
    expect((await visit('/admin', JUDGE_SESSION)).name).toBe('admin-judging');
  });

  it('lets a judge open the judging page', async () => {
    expect((await visit('/admin/judging', JUDGE_SESSION)).name).toBe('admin-judging');
  });

  it('refuses every admin-only page to a judge', async () => {
    const adminOnly = ADMIN_REGISTRY.filter((entry) => entry.minLevel === 3);
    expect(adminOnly.length).toBeGreaterThan(5);
    for (const entry of adminOnly) {
      const route = await visit(`/admin/${entry.path}`, JUDGE_SESSION);
      expect(route.name, entry.path).not.toBe(`admin-${entry.key}`);
      expect(route.path.startsWith('/admin'), entry.path).toBe(false);
    }
  });

  it('only registers judging pages for judges', () => {
    expect(ADMIN_REGISTRY.filter((entry) => entry.minLevel === 2).map((entry) => entry.key)).toEqual(['judging']);
  });

  it('keeps the dashboard for admins', async () => {
    expect((await visit('/admin', ADMIN_SESSION)).name).toBe('admin');
    expect((await visit('/admin/sponsors', ADMIN_SESSION)).name).toBe('admin-sponsors');
  });
});
