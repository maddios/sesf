import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/vue';
import { defineComponent, effectScope, h, nextTick } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { stateFromQuery, stateToQuery, toListQuery, useAdminList, type AdminList } from '../useAdminList';
import type { ListQuery, ListResponse } from '../useAdminResource';
import { ApiError } from '../../../api/client';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function response(names: string[], total = names.length): ListResponse<{ id: number; name: string }> {
  return { data: names.map((name, id) => ({ id, name })), meta: { page: 1, per_page: 25, total, last_page: 1 } };
}

describe('query <-> state', () => {
  it('reads valid values, falls back on junk and keeps unknown keys when writing', () => {
    const state = stateFromQuery(
      { page: '3', per_page: '50', sort: 'URL', direction: 'desc', search: 'acme', 'f.is_enabled': '1', 'f.other': 'x' },
      { sort: 'name' },
      ['is_enabled'],
    );
    expect(state).toEqual({ page: 3, perPage: 50, sort: 'URL', direction: 'desc', search: 'acme', filters: { is_enabled: '1' } });

    const junk = stateFromQuery({ page: '-1', per_page: '1000', direction: 'sideways', sort: ['', 'x'] }, { sort: 'name', direction: 'desc', perPage: 10 });
    expect(junk).toMatchObject({ page: 1, perPage: 10, sort: 'name', direction: 'desc' });

    const query = stateToQuery({ ...state, page: 1, perPage: 25, sort: 'name', direction: 'asc' }, { tab: 'x', page: '9' }, { sort: 'name' }, ['is_enabled']);
    expect(query).toEqual({ tab: 'x', search: 'acme', 'f.is_enabled': '1' });

    expect(toListQuery(state)).toEqual({ page: 3, per_page: 50, sort: 'URL', direction: 'desc', search: 'acme', filter: { is_enabled: '1' } });
    expect(toListQuery({ ...state, search: '' }).search).toBeUndefined();
  });
});

async function mountWithRouter(fetch: (q: ListQuery) => Promise<ListResponse<{ id: number; name: string }>>, path = '/list') {
  let list!: AdminList<{ id: number; name: string }>;
  const Host = defineComponent({
    setup() {
      list = useAdminList({ fetch, defaults: { sort: 'name' }, filterKeys: ['is_enabled'] });
      return () => h('div', list.rows.value.map((row) => row.name).join(','));
    },
  });
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/list', component: Host }] });
  await router.push(path);
  await router.isReady();
  const utils = render({ render: () => h('div', [h((router.currentRoute.value.matched[0]?.components?.default ?? Host) as never)]) }, { global: { plugins: [router] } });
  await flush();
  return { list, router, ...utils };
}

describe('useAdminList with URL sync', () => {
  it('loads from the URL state and writes changes back to the hash query', async () => {
    const fetch = vi.fn(async () => response(['a', 'b'], 2));
    const { list, router } = await mountWithRouter(fetch, '/list?page=2&search=ac');

    expect(fetch).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, search: 'ac', sort: 'name', direction: 'asc' }));
    expect(list.rows.value).toHaveLength(2);
    expect(list.meta.value.total).toBe(2);

    await list.setSort('name');
    await flush();
    expect(router.currentRoute.value.query).toMatchObject({ direction: 'desc', search: 'ac' });
    expect(router.currentRoute.value.query.page).toBeUndefined();

    await list.setSort('URL');
    expect(router.currentRoute.value.query).toMatchObject({ sort: 'URL' });
    expect(router.currentRoute.value.query.direction).toBeUndefined();

    await list.setFilter('is_enabled', '0');
    expect(router.currentRoute.value.query['f.is_enabled']).toBe('0');
    await list.setFilter('is_enabled', null);
    expect(router.currentRoute.value.query['f.is_enabled']).toBeUndefined();

    await list.setPerPage(50);
    await list.setPage(3);
    await list.setSearch('  zed ');
    expect(router.currentRoute.value.query).toMatchObject({ per_page: '50', search: 'zed' });
    await flush();
    expect(fetch).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'zed', per_page: 50, page: 1 }));
  });

  it('drops a stale response that arrives after a newer one', async () => {
    let resolveFirst!: (value: ListResponse<{ id: number; name: string }>) => void;
    const fetch = vi
      .fn<(q: ListQuery) => Promise<ListResponse<{ id: number; name: string }>>>()
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockImplementation(async () => response(['new']));
    const { list } = await mountWithRouter(fetch);

    await list.setSearch('n');
    await flush();
    resolveFirst(response(['old']));
    await flush();
    expect(list.rows.value.map((r) => r.name)).toEqual(['new']);
  });

  it('maps a failed load to an error message and can reload', async () => {
    const fetch = vi.fn().mockRejectedValueOnce(new ApiError(403, { message: 'Forbidden.' })).mockResolvedValue(response(['ok']));
    const { list } = await mountWithRouter(fetch);
    expect(list.error.value).toMatch(/permission/);
    expect(list.loading.value).toBe(false);
    await list.reload();
    expect(list.error.value).toBeNull();
    expect(list.rows.value).toHaveLength(1);
  });
});

describe('useAdminList without the router', () => {
  it('keeps state locally', async () => {
    const fetch = vi.fn(async () => response(['x']));
    const scope = effectScope();
    const list = scope.run(() => useAdminList({ fetch, syncUrl: false, defaults: { perPage: 10 } }))!;
    await flush();
    await list.setFilter('a', '1');
    await nextTick();
    await flush();
    expect(fetch).toHaveBeenLastCalledWith(expect.objectContaining({ per_page: 10, filter: { a: '1' } }));
    scope.stop();
  });
});
