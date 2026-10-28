import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import ExtrasPage from '../ExtrasPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { Extra } from '../extras';

function extra(overrides: Partial<Extra> = {}): Extra {
  return {
    id: 1,
    show_id: 4,
    show_name: 'Spring Show',
    name: 'Dinner',
    description: 'Saturday dinner',
    auth_key: 'dinner36',
    price: '40.00',
    type_id: 2,
    type: 'Guests',
    min_value: 0,
    max_value: 3,
    is_enabled: true,
    registrations_count: 0,
    in_use: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const Page = defineComponent({ render: () => [h(ExtrasPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const ROWS = [extra(), extra({ id: 2, name: 'Pin', type_id: 1, type: 'Cars', price: '5.00', auth_key: null, is_enabled: false, registrations_count: 3, in_use: true })];

function stubExtras(rows: Extra[] = ROWS, extraHandler: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extraHandler(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/extras') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Extras' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('ExtrasPage', () => {
  it('lists extras with price, type and registrations, and passes axe', async () => {
    const { seen } = stubExtras();
    const { container } = await renderAt(Page, '/admin/extras');
    expect(await within(table()).findByText('Dinner')).toBeTruthy();
    expect(within(table()).getByText('$40.00')).toBeTruthy();
    expect(within(table()).getByText('Disabled')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/extras')?.params.get('sort')).toBe('name');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('syncs the event, type and status filters with the URL', async () => {
    const { seen } = stubExtras();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/extras?f.type_id=2');
    await within(table()).findByText('Dinner');
    expect(seen.find((r) => r.path === '/admin-api/extras')?.params.get('filter[type_id]')).toBe('2');

    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await user.click(await screen.findByRole('option', { name: 'Disabled' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.is_enabled']).toBe('0'));
    await user.type(screen.getByRole('combobox', { name: 'Event' }), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring Show (2026)' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.show_id']).toBe('4'));
  });

  it('creates an extra in the chosen event with the price as a decimal string', async () => {
    const { seen } = stubExtras(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/extras' ? jsonResponse(201, { data: extra({ id: 3, name: 'Shirt' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extras?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'New extra' }));
    const dialog = await screen.findByRole('dialog', { name: 'New extra' });
    await user.type(within(dialog).getByLabelText('Name (required)'), 'Shirt');
    await user.type(within(dialog).getByLabelText('Price (required)'), '12.5');
    await user.tab();
    await user.click(within(dialog).getByRole('button', { name: 'Create extra' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const body = seen.find((r) => r.method === 'POST' && r.path === '/admin-api/extras')?.body as Record<string, unknown>;
    expect(body.show_id).toBe(4);
    expect(body.price).toBe('12.50');
    expect(body.auth_key).toBeNull();
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Extra “Shirt” created.');
  });

  it('needs an event before an extra can be created', async () => {
    stubExtras();
    await renderAt(Page, '/admin/extras');
    const button = (await screen.findByRole('button', { name: 'New extra' })) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
  });

  it('maps a duplicate landing key onto the field', async () => {
    stubExtras(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/extras/1'
        ? jsonResponse(422, { message: 'Invalid.', errors: { auth_key: ['The auth key has already been taken.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extras');
    await user.click(await within(table()).findByRole('button', { name: 'Edit Dinner' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Dinner' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('The auth key has already been taken.')).toBeTruthy();
    expect((within(dialog).getByLabelText(/Landing key/) as HTMLInputElement).getAttribute('aria-invalid')).toBe('true');
  });

  it('shows the server message when an extra with registrations cannot be deleted', async () => {
    const { seen } = stubExtras(ROWS, (r) =>
      r.method === 'DELETE' && r.path === '/admin-api/extras/2'
        ? jsonResponse(409, { message: 'This extra has 3 registration(s) and cannot be deleted. Disable it instead.', references: { registrations: 3 } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/extras');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Pin' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete extra' });
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    expect(await screen.findByText('This extra has 3 registration(s) and cannot be deleted. Disable it instead.')).toBeTruthy();
    expect(seen.some((r) => r.method === 'DELETE')).toBe(true);
  });
});
