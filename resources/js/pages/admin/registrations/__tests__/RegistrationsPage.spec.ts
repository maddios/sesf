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
import { centsToDollars, dollarsToCents, formatCents, type Registration } from '../registrations';

function registration(overrides: Partial<Registration> = {}): Registration {
  return {
    id: 1,
    invoice_id: 1001,
    show_id: 4,
    show_name: 'Spring Show',
    user_id: 9,
    user_name: 'Ada Lovelace',
    editor_path: '/SpringShow/e4/register?user_id=9',
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
    stored_owed_cents: 4500,
    received_actual_cents: null,
    discount_note: null,
    car_ids: [7],
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
    if (request.method === 'GET' && request.path === '/admin-api/registrations/1/cars' && !request.params.has('user_id')) {
      return jsonResponse(200, { data: [{ id: 7, label: '1969 Camaro', selected: true }, { id: 8, label: '1972 Nova', selected: false }] });
    }
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    if (request.path === '/admin-api/users/lookup') {
      return jsonResponse(200, { data: [{ id: 9, label: 'Ada Lovelace <ada@example.test>' }, { id: 12, label: 'Grace Hopper <grace@example.test>' }] });
    }
    if (request.method === 'GET' && request.path === '/admin-api/registrations/1/cars' && request.params.get('user_id') === '12') {
      return jsonResponse(200, { data: [{ id: 7, label: '1969 Camaro', selected: true }, { id: 20, label: '1957 Bel Air', selected: false }] });
    }
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
    await user.click(within(dialog).getByRole('combobox', { name: /^Donation/ }));
    await user.click(await screen.findByRole('option', { name: '$5' }));
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({
      donation_cents: 500,
      arrival_date: '2026-05-02',
      arrival_time: 'morning',
      is_cancelled: false,
      user_id: 9,
      invoice_id: 1001,
      car_ids: [7],
      amount_received_cents: 0,
      amount_received_actual_cents: null,
      discount_cents: 0,
      discount_note: null,
      is_paid: null,
      amount_owed_cents: null,
      expected_amount_received_cents: 0,
      expected_discount_cents: 0,
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
      user_id: 9,
      invoice_id: 1001,
      car_ids: [7],
      amount_received_cents: 0,
      amount_received_actual_cents: null,
      discount_cents: 0,
      discount_note: null,
      is_paid: null,
      amount_owed_cents: null,
      expected_amount_received_cents: 0,
      expected_discount_cents: 0,
    });
    expect(await screen.findByText('Registration #1001 cancelled.')).toBeTruthy();
  });

  it('sends the loaded received and discount, and offers a reload when the server says they changed', async () => {
    let gets = 0;
    const { seen } = stubRegistrations([registration({ received_cents: 2500, discount_cents: 100 })], (r) => {
      if (r.method === 'PUT') return jsonResponse(409, { message: 'This registration changed since you opened it.', code: 'registration_changed' });
      if (r.method === 'GET' && r.path === '/admin-api/registrations/1') {
        gets++;
        return jsonResponse(200, { data: registration({ received_cents: 17500, discount_cents: 100 }) });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    const prompt = await screen.findByRole('dialog', { name: 'Registration changed' });
    expect(seen.find((r) => r.method === 'PUT')?.body).toMatchObject({ expected_amount_received_cents: 2500, expected_discount_cents: 100 });
    await user.click(within(prompt).getByRole('button', { name: 'Reload' }));

    await waitFor(() => expect(gets).toBe(1));
    const reloaded = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    await waitFor(() => expect((within(reloaded).getByLabelText('Amount received ($)') as HTMLInputElement).value).toBe('175.00'));
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

  it('saves payments, a signed discount, a paid override and an added car in cents', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/registrations/1' ? jsonResponse(200, { data: registration() }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    await user.click(within(dialog).getByRole('checkbox', { name: '1972 Nova' }));
    await user.clear(within(dialog).getByLabelText('Amount received ($)'));
    await user.type(within(dialog).getByLabelText('Amount received ($)'), '40.5');
    await user.clear(within(dialog).getByLabelText('Discount ($)'));
    await user.type(within(dialog).getByLabelText('Discount ($)'), '-30');
    await user.type(within(dialog).getByLabelText('Discount note'), 'Late fee');
    await user.click(within(dialog).getByRole('combobox', { name: /^Paid/ }));
    await user.click(await screen.findByRole('option', { name: 'Paid' }));
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'PUT')?.body).toMatchObject({
      car_ids: [7, 8],
      amount_received_cents: 4050,
      discount_cents: -3000,
      discount_note: 'Late fee',
      is_paid: true,
      amount_owed_cents: null,
    });
  });

  it('maps a 422 on a money field to that field', async () => {
    stubRegistrations(undefined, (r) =>
      r.method === 'PUT' ? jsonResponse(422, { message: 'Invalid.', errors: { amount_received_cents: ['The amount received cents field must be an integer.'] } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    await user.type(within(dialog).getByLabelText('Amount received ($)'), 'abc');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('The amount received cents field must be an integer.')).toBeTruthy();
  });

  it('links each row to the full on-behalf editor', async () => {
    stubRegistrations();
    await renderAt(Page, '/admin/registrations');
    const link = await within(table()).findByRole('link', { name: 'Open the full editor for registration 1001' });
    expect(link.getAttribute('href')).toContain('/SpringShow/e4/register?user_id=9');
  });

  it('opens the edit dialog when the row is clicked', async () => {
    stubRegistrations();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByText('1001'));
    expect(await screen.findByRole('dialog', { name: 'Edit registration 1001' })).toBeTruthy();
  });

  it('deletes after the typed confirmation and reports a 409 as an error', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'DELETE' ? jsonResponse(409, { message: 'This registration has payments or waivers (paypal_payment) and cannot be deleted. Cancel it instead.' }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Delete registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete registration' });
    await user.type(within(dialog).getByRole('textbox'), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE' && r.path === '/admin-api/registrations/1')).toBe(true));
    expect(await screen.findByText(/Cancel it instead/)).toBeTruthy();
  });

  it('adds a guest to a registration', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/registration-guests' ? jsonResponse(201, { data: { id: 5 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Add a guest to registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add a guest to registration 1001' });
    await user.type(within(dialog).getByLabelText('First name (required)'), 'Ann');
    await user.type(within(dialog).getByLabelText('Last name (required)'), 'Guest');
    await user.click(within(dialog).getByRole('button', { name: 'Add guest' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/registration-guests')?.body).toEqual({ first: 'Ann', last: 'Guest', email: null, type_id: 1, registration_id: 1 });
  });

  it('adds an extra of the registration\'s event', async () => {
    const { seen } = stubRegistrations(undefined, (r) => {
      if (r.method === 'GET' && r.path === '/admin-api/extras') return jsonResponse(200, listBody([{ id: 3, name: 'Dinner' }]));
      if (r.method === 'POST' && r.path === '/admin-api/extra-registrations') return jsonResponse(201, { data: { id: 8 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await within(table()).findByRole('button', { name: 'Add an extra to registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add an extra to registration 1001' });
    expect(seen.find((r) => r.path === '/admin-api/extras')?.params.get('filter[show_id]')).toBe('4');
    await user.click(within(dialog).getByRole('combobox', { name: /^Extra/ }));
    await user.click(await screen.findByRole('option', { name: 'Dinner' }));
    await user.clear(within(dialog).getByLabelText('Quantity (required)'));
    await user.type(within(dialog).getByLabelText('Quantity (required)'), '2');
    await user.click(within(dialog).getByRole('button', { name: 'Add extra' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/extra-registrations')?.body).toEqual({ registration_id: 1, show_extra_id: 3, num_extras: 2 });
  });

  it('creates a registration for a picked member and offers the full editor', async () => {
    const { seen } = stubRegistrations(undefined, (r) => {
      if (r.path === '/admin-api/users/lookup') return jsonResponse(200, { data: [{ id: 9, label: 'Ada Lovelace <ada@example.test>' }] });
      if (r.method === 'GET' && r.path === '/admin-api/cars') return jsonResponse(200, listBody([{ id: 7, year: '1969', model: 'Camaro', nickname: null }]));
      if (r.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)', url_name: 'SpringShow' }] });
      if (r.method === 'POST' && r.path === '/admin-api/users/9/registrations') return jsonResponse(201, { data: { registration_id: 3 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    await user.click(await screen.findByRole('button', { name: 'New registration' }));
    const dialog = await screen.findByRole('dialog', { name: 'Register for an event' });
    await user.type(within(dialog).getByRole('combobox', { name: /Member/ }), 'ada');
    await user.click(await screen.findByRole('option', { name: 'Ada Lovelace <ada@example.test>' }));
    await user.type(within(dialog).getByRole('combobox', { name: /Event/ }), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring Show (2026)' }));
    expect(within(dialog).getByRole('link', { name: /full registration editor/ }).getAttribute('href')).toBe('#/SpringShow/e4/register?user_id=9');
    await user.click(await within(dialog).findByRole('checkbox', { name: '1969 Camaro' }));
    await user.click(within(dialog).getByRole('button', { name: 'Register' }));
    await waitFor(() => expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/users/9/registrations')?.body).toEqual({ show_id: 4, car_ids: [7] }));
  });

  it('reassigns a registration to another member and offers that member\'s cars', async () => {
    const { seen } = stubRegistrations(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/registrations/1' ? jsonResponse(200, { data: registration({ user_id: 12 }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/registrations');
    expect(await within(table()).findByRole('columnheader', { name: /Date registered/ })).toBeTruthy();
    await user.click(await within(table()).findByRole('button', { name: 'Edit registration 1001' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit registration 1001' });
    const member = within(dialog).getByRole('combobox', { name: /Member/ });
    await user.clear(member);
    await user.type(member, 'grace');
    await user.click(await screen.findByRole('option', { name: 'Grace Hopper <grace@example.test>' }));
    await user.click(await within(dialog).findByRole('checkbox', { name: '1957 Bel Air' }));
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(seen.find((r) => r.method === 'PUT')?.body).toMatchObject({ user_id: 12, car_ids: [7, 20] }));
  });

  it('converts between signed dollar text and cents', () => {
    expect(centsToDollars(-3000)).toBe('-30.00');
    expect(centsToDollars(5)).toBe('0.05');
    expect(centsToDollars(null)).toBe('');
    expect(dollarsToCents('-30', 0)).toBe(-3000);
    expect(dollarsToCents('$12.5', 0)).toBe(1250);
    expect(dollarsToCents('', null)).toBeNull();
    expect(dollarsToCents('12.345', 0)).toBe('12.345');
  });

  it('formats credits with a minus sign', () => {
    expect(formatCents(-3000)).toBe('-$30.00');
    expect(formatCents(123450)).toBe('$1,234.50');
  });
});
