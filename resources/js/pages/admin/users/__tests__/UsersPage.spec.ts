import { describe, it, expect, afterEach, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import UsersPage from '../UsersPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest, chooseRowAction, rowMenuItem } from '../../../../test/admin';
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
    profile_url: '/#/AdaL/u1',
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
    expect(within(table()).getByRole('button', { name: 'Edit Ada Lovelace' })).toBeTruthy();
    expect(within(table()).getByRole('button', { name: 'Actions for Bo Gone' })).toBeTruthy();
    const list = seen.find((r) => r.path === '/admin-api/users')!;
    expect(list.params.get('sort')).toBe('last');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('syncs the status filter with the URL and asks for disabled members', async () => {
    const { seen } = stubUsers();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/users');
    await within(table()).findByText('Ada Lovelace');
    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await user.click(await screen.findByRole('option', { name: 'Disabled' }));
    expect(router.currentRoute.value.query['f.status']).toBe('disabled');
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/users').at(-1)?.params.get('filter[status]')).toBe('disabled'));
  });

  it('opens the edit dialog when a row is clicked', async () => {
    stubUsers();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await user.click(await within(table()).findByText('Ada Lovelace'));
    expect(await screen.findByRole('dialog', { name: 'Edit Ada Lovelace' })).toBeTruthy();
  });

  it('logs in as a member only after confirmation, then goes to the site', async () => {
    const { seen } = stubUsers(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/users/1/impersonate' ? jsonResponse(200, { data: { user_id: 1 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await chooseRowAction(user, table(), 'Ada Lovelace', 'Log in as member');
    const dialog = await screen.findByRole('dialog', { name: 'Log in as this member' });
    await user.click(within(dialog).getByRole('button', { name: 'Log in as member' }));

    await waitFor(() => expect(goToSite).toHaveBeenCalled());
    expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/users/1/impersonate')).toBe(true);
  });

  it('cancelling log in as sends nothing', async () => {
    const { seen } = stubUsers();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await chooseRowAction(user, table(), 'Ada Lovelace', 'Log in as member');
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
    await chooseRowAction(user, table(), 'Ada Lovelace', 'Disable…');
    await user.click(await screen.findByRole('button', { name: 'Disable' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));

    await chooseRowAction(user, table(), 'Bo Gone', 'Restore');
    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/users/2/restore')).toBe(true));
  });

  it('registers a member for an event with one of their cars', async () => {
    const { seen } = stubUsers(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/users/1/registrations' ? jsonResponse(201, { data: { registration_id: 9, show_id: 4, user_id: 1 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await chooseRowAction(user, table(), 'Ada Lovelace', 'Register for an event');
    const dialog = await screen.findByRole('dialog', { name: 'Register Ada Lovelace for an event' });
    await user.type(within(dialog).getByRole('combobox'), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring (2026)' }));
    await user.click(await within(dialog).findByRole('checkbox', { name: /Camaro/ }));
    await user.click(within(dialog).getByRole('button', { name: 'Register' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/users/1/registrations')?.body).toEqual({ show_id: 4, car_ids: [7] });
    expect(seen.find((r) => r.path === '/admin-api/cars')?.params.get('filter[user_id]')).toBe('1');
  });

  it('shows the date registered, date deleted and profile link columns', async () => {
    stubUsers([member({ created_at: '2024-03-05T15:00:00+00:00' }), member({ id: 2, full_name: 'Bo Gone', is_disabled: true, disabled_at: '2026-01-02T15:00:00+00:00', profile_url: '/#/BoG/u2' })]);
    await renderAt(Page, '/admin/users');
    expect(await within(table()).findByText('Mar 5, 2024')).toBeTruthy();
    expect(within(table()).getByText('Jan 2, 2026')).toBeTruthy();
    const link = within(table()).getByRole('link', { name: /Profile.*Bo Gone/ });
    expect(link.getAttribute('href')).toBe('/#/BoG/u2');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it('creates a member with a password', async () => {
    const { seen } = stubUsers(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/users' ? jsonResponse(201, { data: member({ id: 5, full_name: 'New Person' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await within(table()).findByText('Ada Lovelace');
    await user.click(screen.getByRole('button', { name: 'Add member' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add member' });
    await user.type(within(dialog).getByLabelText('First name'), 'New');
    await user.type(within(dialog).getByLabelText('Email (required)'), 'new@example.test');
    await user.type(within(dialog).getByLabelText('Street (required)'), '2 Elm St');
    await user.type(within(dialog).getByLabelText('City (required)'), 'Tampa');
    await user.type(within(dialog).getByLabelText('State (required)'), 'FL');
    await user.type(within(dialog).getByLabelText('Zip (required)'), '33601');
    expect(within(dialog).getByLabelText(/^Password \(required\)/).getAttribute('type')).toBe('password');
    expect(within(dialog).getByLabelText(/^Password \(required\)/).getAttribute('autocomplete')).toBe('new-password');
    await user.type(within(dialog).getByLabelText(/^Password \(required\)/), ' secret1 ');
    await user.type(within(dialog).getByLabelText('Confirm password (required)'), ' secret1 ');
    await user.click(within(dialog).getByRole('button', { name: 'Create member' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/users')).toBe(true));
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/users')?.body).toMatchObject({
      first: 'New',
      email: 'new@example.test',
      type_id: 1,
      password: ' secret1 ',
      password_confirmation: ' secret1 ',
    });
    expect(await screen.findByText('Member “New Person” created.')).toBeTruthy();
  });

  it('sets a member password and shows the 422 on the field', async () => {
    let attempts = 0;
    const { seen } = stubUsers(undefined, (r) => {
      if (r.method !== 'POST' || r.path !== '/admin-api/users/1/password') return undefined;
      attempts += 1;
      return attempts === 1
        ? jsonResponse(422, { message: 'Invalid', errors: { password: ['The password field confirmation does not match.'] } })
        : jsonResponse(200, { message: 'The password has been changed.' });
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await chooseRowAction(user, table(), 'Ada Lovelace', 'Set password');
    const dialog = await screen.findByRole('dialog', { name: 'Set password for Ada Lovelace' });
    await user.type(within(dialog).getByLabelText(/^Password \(required\)/), 'secret12');
    await user.type(within(dialog).getByLabelText('Confirm password (required)'), 'secret13');
    await user.click(within(dialog).getByRole('button', { name: 'Set password' }));
    expect(await within(dialog).findByText('The password field confirmation does not match.')).toBeTruthy();

    await user.clear(within(dialog).getByLabelText('Confirm password (required)'));
    await user.type(within(dialog).getByLabelText('Confirm password (required)'), 'secret12');
    await user.click(within(dialog).getByRole('button', { name: 'Set password' }));

    expect(await screen.findByText('Password changed for Ada Lovelace.')).toBeTruthy();
    expect(seen.filter((r) => r.path === '/admin-api/users/1/password').at(-1)?.body).toEqual({ password: 'secret12', password_confirmation: 'secret12' });
  });

  it("disables set password and reset for another administrator's account, saying why", async () => {
    const { seen } = stubUsers([member(), member({ id: 9, full_name: 'Other Admin', type_id: 3, type_name: 'Administrator' })]);
    const user = userEvent.setup();
    await renderAt(Page, '/admin/users');
    await within(table()).findByText('Other Admin');
    const setPassword = await rowMenuItem(user, table(), 'Other Admin', /^Set password/);
    expect(setPassword.getAttribute('aria-disabled')).toBe('true');
    expect(setPassword.textContent).toContain('Each administrator manages their own sign-in.');
    const reset = screen.getByRole('menuitem', { name: /^Send password reset/ });
    expect(reset.getAttribute('aria-disabled')).toBe('true');
    await user.click(reset);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(seen.some((r) => r.path.endsWith('/password-reset'))).toBe(false);
    await user.keyboard('{Escape}');
    expect((await rowMenuItem(user, table(), 'Ada Lovelace', 'Send password reset')).getAttribute('aria-disabled')).toBeNull();
  });
});
