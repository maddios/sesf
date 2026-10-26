import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import RegistrationsPage from '../RegistrationsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { formatCents, type Registration } from '../registrations';

function registration(overrides: Partial<Registration> = {}): Registration {
  return {
    id: 1,
    invoice_id: 1001,
    show_id: 4,
    show_name: 'Spring Show',
    user_id: 9,
    user_name: 'Ada Lovelace',
    donation_cents: 0,
    arrival_date: '2026-05-02',
    arrival_time: 'morning',
    is_cancelled: false,
    is_paid: false,
    amount_source: 'computed',
    total_cents: 4500,
    owed_cents: 4500,
    received_cents: 0,
    discount_cents: 0,
    cars_count: 1,
    guests_count: 2,
    shirts_count: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

/** The page plus the shared dialog/toast hosts that AdminLayout normally provides. */
const Page = defineComponent({ render: () => [h(RegistrationsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function stubRegistrations(rows: Registration[] = [registration(), registration({ id: 2, invoice_id: 1002, is_cancelled: true, amount_source: 'stored', total_cents: 1234, owed_cents: 1234 })], extra: (r: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/registrations') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Registrations' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('RegistrationsPage', () => {
  it('lists counts, money and the stored badge for ended shows, and passes axe', async () => {
    const { seen } = stubRegistrations();
    const { container } = await renderAt(Page, '/admin/registrations');
    expect(await within(table()).findAllByText('Spring Show')).toHaveLength(2);
    expect(within(table()).getAllByText('$45.00')).toHaveLength(2);
    expect(within(table()).getByText('stored')).toBeTruthy();
    expect(within(table()).getByText('Cancelled')).toBeTruthy();
    const list = seen.find((r) => r.path === '/admin-api/registrations')!;
    expect(list.params.get('sort')).toBe('invoice_id');
    expect(list.params.get('direction')).toBe('desc');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('offers a $5 donation and saves it with the arrival and cancel values', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/registrations/1' ? jsonResponse(200, { data: registration({ donation_cents: 500 }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    const donation = within(dialog).getByLabelText('Donation (required)') as HTMLSelectElement;
    expect(within(donation).getByRole('option', { name: '$5' })).toBeTruthy();
    await user.selectOptions(donation, '500');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({
      donation_cents: 500,
      arrival_date: '2026-05-02',
      arrival_time: 'morning',
      is_cancelled: false,
    });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Registration #1001 saved.');
  });

  it('maps a 422 on the arrival date to its field', async () => {
    stubRegistrations(undefined, (r) =>
      r.method === 'PUT'
        ? jsonResponse(422, { message: 'Invalid.', errors: { arrival_date: ['Arrival Date must be between 2026-05-01 and 2026-05-03'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('Arrival Date must be between 2026-05-01 and 2026-05-03')).toBeTruthy();
  });

  it('cancels only after confirming, keeping the current values', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/registrations/1' ? jsonResponse(200, { data: registration({ is_cancelled: true }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Cancel registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Cancel registration' });
    expect(seen.some((r) => r.method === 'PUT')).toBe(false);
    await user.click(within(dialog).getByRole('button', { name: 'Cancel registration' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'PUT')).toBe(true));
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({
      donation_cents: 0,
      arrival_date: '2026-05-02',
      arrival_time: 'morning',
      is_cancelled: true,
    });
    expect(await screen.findByText('Registration #1001 cancelled.')).toBeTruthy();
  });

  it('filters by event through the RelationSelect and syncs the URL', async () => {
    const { seen } = stubRegistrations();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/registrations');
    await within(table()).findAllByText('Spring Show');
    await user.type(screen.getByRole('combobox', { name: 'Event' }), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring Show (2026)' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.show_id']).toBe('4'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/registrations').at(-1)?.params.get('filter[show_id]')).toBe('4'));
  });

  it('formats credits with a minus sign', () => {
    expect(formatCents(-3000)).toBe('-$30.00');
    expect(formatCents(123450)).toBe('$1,234.50');
  });
});
