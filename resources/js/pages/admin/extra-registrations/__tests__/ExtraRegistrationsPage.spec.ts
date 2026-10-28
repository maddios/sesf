import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import ExtraRegistrationsPage from '../ExtraRegistrationsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { ExtraRegistration } from '../extraRegistrations';

function row(overrides: Partial<ExtraRegistration> = {}): ExtraRegistration {
  return {
    id: 1,
    registration_id: 10,
    user_name: 'Ada Lovelace',
    show_id: 4,
    show_name: 'Spring Show',
    show_extra_id: 5,
    extra_name: 'Dinner',
    num_extras: 2,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const Page = defineComponent({ render: () => [h(ExtraRegistrationsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const ROWS = [row(), row({ id: 2, user_name: 'Grace Hopper', extra_name: 'Pin', num_extras: 1 })];

function stubRows(rows: ExtraRegistration[] = ROWS, extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/extra-registrations') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    if (request.method === 'GET' && request.path === '/admin-api/extras') return jsonResponse(200, listBody([{ id: 5, name: 'Dinner' }, { id: 6, name: 'Breakfast' }]));
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('ExtraRegistrationsPage', () => {
  it('lists purchases with member, event, extra and quantity, and passes axe', async () => {
    const { container, seen } = await (async () => {
      const stub = stubRows();
      const rendered = await renderAt(Page, '/admin/extra-registrations');
      return { ...rendered, seen: stub.seen };
    })();
    const table = await screen.findByRole('table', { name: 'Extra registrations' });
    expect(await within(table).findByText('Ada Lovelace')).toBeTruthy();
    expect(within(table).getAllByText('Spring Show')).toHaveLength(2);
    expect(seen.find((r) => r.path === '/admin-api/extra-registrations')?.params.get('sort')).toBe('created_at');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('filters by event through the URL', async () => {
    const { seen } = stubRows();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/extra-registrations');
    await screen.findAllByText('Ada Lovelace');
    await user.type(screen.getByRole('combobox', { name: 'Event' }), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring Show (2026)' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.show_id']).toBe('4'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/extra-registrations').at(-1)?.params.get('filter[show_id]')).toBe('4'));
  });

  it('changes the quantity of a purchase', async () => {
    const { seen } = stubRows(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/extra-registrations/1' ? jsonResponse(200, { data: row({ num_extras: 3 }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extra-registrations');
    await user.click(await within(await screen.findByRole('table', { name: 'Extra registrations' })).findByRole('button', { name: 'Edit purchase for Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit purchase' });
    const quantity = within(dialog).getByLabelText('Quantity (required)');
    await user.clear(quantity);
    await user.type(quantity, '3');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({ num_extras: 3, show_extra_id: 5 });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Quantity for Ada Lovelace saved.');
  });

  it('removes a purchase when the quantity is set to 0', async () => {
    stubRows(ROWS, (r) => (r.method === 'PUT' && r.path === '/admin-api/extra-registrations/1' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extra-registrations');
    await user.click(await within(await screen.findByRole('table', { name: 'Extra registrations' })).findByRole('button', { name: 'Edit purchase for Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit purchase' });
    const quantity = within(dialog).getByLabelText('Quantity (required)');
    await user.clear(quantity);
    await user.type(quantity, '0');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await screen.findByText('Extra purchase for Ada Lovelace removed.')).toBeTruthy();
  });

  it('moves a purchase to another extra of the event', async () => {
    const { seen } = stubRows(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/extra-registrations/1' ? jsonResponse(200, { data: row({ show_extra_id: 6, extra_name: 'Breakfast' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extra-registrations');
    await user.click(await within(await screen.findByRole('table', { name: 'Extra registrations' })).findByRole('button', { name: 'Edit purchase for Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit purchase' });
    expect(seen.find((r) => r.path === '/admin-api/extras')?.params.get('filter[show_id]')).toBe('4');
    await user.selectOptions(within(dialog).getByLabelText('Extra (required)'), '6');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({ num_extras: 2, show_extra_id: 6 }));
  });

  it('filters by registration id through the URL', async () => {
    const { seen } = stubRows();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/extra-registrations');
    await screen.findByRole('table', { name: 'Extra registrations' });
    await user.type(screen.getByLabelText('Registration ID'), '10{Enter}');
    await user.tab();
    await waitFor(() => expect(router.currentRoute.value.query['f.registration_id']).toBe('10'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/extra-registrations').at(-1)?.params.get('filter[registration_id]')).toBe('10'));
  });

  it('maps a server quantity error onto the field', async () => {
    stubRows(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/extra-registrations/1'
        ? jsonResponse(422, { message: 'Invalid.', errors: { num_extras: ['The num extras field must be at least 0.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extra-registrations');
    await user.click(await within(await screen.findByRole('table', { name: 'Extra registrations' })).findByRole('button', { name: 'Edit purchase for Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit purchase' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('The num extras field must be at least 0.')).toBeTruthy();
  });

  it('deletes a purchase only after the typed confirmation', async () => {
    const { seen } = stubRows(ROWS, (r) => (r.method === 'DELETE' && r.path === '/admin-api/extra-registrations/2' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extra-registrations');
    await user.click(await within(await screen.findByRole('table', { name: 'Extra registrations' })).findByRole('button', { name: 'Delete Pin for Grace Hopper' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete extra purchase' });
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Extra purchase deleted.')).toBeTruthy();
  });
});
