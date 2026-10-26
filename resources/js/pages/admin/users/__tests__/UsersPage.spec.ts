import { describe, it, expect, afterEach, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import UsersPage from '../UsersPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { goToSite, type AdminUser } from '../users';

vi.mock('../users', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../users')>()),
  goToSite: vi.fn(),
}));

function member(overrides: Partial<AdminUser> = {}): AdminUser {
  return {
    id: 1,
    first: 'Ada',
    last: 'Lovelace',
    full_name: 'Ada Lovelace',
    email: 'ada@example.test',
    phone: null,
    phone2: null,
    heard_from: null,
    address1: '1 Main St',
    address2: null,
    city: 'Orlando',
    state: 'FL',
    zip: '32803',
    type_id: 1,
    type_name: 'Regular',
    is_confirmed: 1,
    has_image: false,
    image_url: 'images/generic.gif',
    is_disabled: false,
    disabled_at: null,
    cars_count: 2,
    date_registered: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const Page = defineComponent({ render: () => [h(UsersPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function stubUsers(rows: AdminUser[] = [member(), member({ id: 2, full_name: 'Bo Gone', last: 'Gone', is_disabled: true, disabled_at: '2026-01-01T00:00:00Z' })], extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/users') return jsonResponse(200, listBody(rows));
    if (request.method === 'GET' && request.path === '/admin-api/cars') {
      return jsonResponse(200, listBody([{ id: 7, year: '1969', model: 'Camaro', nickname: null }]));
    }
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Members' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
  vi.mocked(goToSite).mockClear();
});

describe('UsersPage', () => {
  it('lists active members by default with actions, and passes axe', async () => {
    const { seen } = stubUsers();
    const { container } = await renderAt(Page, '/admin/users');
    expect(await within(table()).findByText('Ada Lovelace')).toBeTruthy();
    expect(within(table()).queryByText('Bo Gone')).toBeTruthy();
    expect(within(table()).getByRole('button', { name: 'Log in as Ada Lovelace' })).toBeTruthy();
    expect(within(table()).getByRole('button', { name: 'Restore Bo Gone' })).toBeTruthy();
    const list = seen.find((r) => r.path === '/admin-api/users')!;
    expect(list.params.get('sort')).toBe('last');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('syncs the status filter with the URL and asks for disabled members', async () => {
    const { seen } = stubUsers();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/users');
    await within(table()).findByText('Ada Lovelace');
    await user.selectOptions(screen.getByLabelText('Status'), 'disabled');
    expect(router.currentRoute.value.query['f.status']).toBe('disabled');
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/users').at(-1)?.params.get('filter[status]')).toBe('disabled'));
  });

  it('logs in as a member only after confirmation, then goes to the site', async () => {
    const { seen } = stubUsers(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/users/1/impersonate' ? jsonResponse(200, { data: { user_id: 1 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await user.click(await within(table()).findByRole('button', { name: 'Log in as Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Log in as this member' });
    await user.click(within(dialog).getByRole('button', { name: 'Log in as member' }));

    await waitFor(() => expect(goToSite).toHaveBeenCalled());
    expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/users/1/impersonate')).toBe(true);
  });

  it('cancelling log in as sends nothing', async () => {
    const { seen } = stubUsers();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await user.click(await within(table()).findByRole('button', { name: 'Log in as Ada Lovelace' }));
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(seen.some((r) => r.path === '/admin-api/users/1/impersonate')).toBe(false);
    expect(goToSite).not.toHaveBeenCalled();
  });

  it('disables and restores a member through the resource routes', async () => {
    const { seen } = stubUsers(undefined, (r) => {
      if (r.method === 'DELETE' && r.path === '/admin-api/users/1') return new Response(null, { status: 204 });
      if (r.method === 'POST' && r.path === '/admin-api/users/2/restore') return jsonResponse(200, { data: member({ id: 2 }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await user.click(await within(table()).findByRole('button', { name: 'Disable Ada Lovelace' }));
    await user.click(await screen.findByRole('button', { name: 'Disable' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));

    await user.click(await within(table()).findByRole('button', { name: 'Restore Bo Gone' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/users/2/restore')).toBe(true));
  });

  it('registers a member for an event with one of their cars', async () => {
    const { seen } = stubUsers(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/users/1/registrations' ? jsonResponse(201, { data: { registration_id: 9, show_id: 4, user_id: 1 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await user.click(await within(table()).findByRole('button', { name: 'Register Ada Lovelace for an event' }));
    const dialog = await screen.findByRole('dialog', { name: 'Register Ada Lovelace for an event' });
    await user.type(within(dialog).getByRole('combobox'), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring (2026)' }));
    await user.click(await within(dialog).findByRole('checkbox', { name: /Camaro/ }));
    await user.click(within(dialog).getByRole('button', { name: 'Register' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/users/1/registrations')?.body).toEqual({ show_id: 4, car_ids: [7] });
    expect(seen.find((r) => r.path === '/admin-api/cars')?.params.get('filter[user_id]')).toBe('1');
  });
});
