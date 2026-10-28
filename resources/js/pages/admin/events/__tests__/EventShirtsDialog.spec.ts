import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import EventShirtsDialog from '../EventShirtsDialog.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import type { Event, EventShirtType } from '../events';

function event(): Event {
  return {
    id: 1, name: 'Spring Show', short_name: 'spring', description: null, short_description: null, schedule: null,
    is_schedule_pdf: false, price_car: null, price_adult: null, price_child: null, date_start: null, date_end: null,
    date_active: null, date_registration_deactivation: null, is_active: true, is_judging_released: false, waiver_id: null, has_image: false,
    image_url: '', primary_sponsor_id: null, registrations_count: 0, created_at: null, updated_at: null,
  };
}

function type(id: number, name: string, overrides: Partial<EventShirtType> = {}): EventShirtType {
  return { id, name, default_price: '35.00', price: null, offered: true, orders: 0, ...overrides };
}

const URL = '/admin-api/events/1/shirts';

const Host = defineComponent({
  render: () => [h(EventShirtsDialog, { open: true, event: event(), 'onUpdate:open': () => undefined }), h(AdminToastRegion)],
});

function stubShirts(types: EventShirtType[], configured: boolean, onPut: (r: SeenRequest) => Response = (r) => {
  const body = r.body as { types: Array<{ id: number; price: string | null }> };
  const chosen = new Map(body.types.map((t) => [t.id, t.price]));
  return jsonResponse(200, {
    data: { configured: true, types: types.map((t) => ({ ...t, offered: chosen.has(t.id), price: chosen.get(t.id) ?? null })) },
    removed_with_orders: types.filter((t) => t.orders > 0 && !chosen.has(t.id)).map((t) => ({ id: t.id, name: t.name, orders: t.orders })),
  });
}) {
  return stubAdminFetch((request) => {
    if (request.path !== URL) return undefined;
    return request.method === 'PUT' ? onPut(request) : jsonResponse(200, { data: { configured, types } });
  });
}

function putBody(seen: SeenRequest[]): unknown {
  return seen.find((r) => r.method === 'PUT' && r.path === URL)?.body;
}

afterEach(() => {
  useToast().clear();
  document.body.style.overflow = '';
});

describe('EventShirtsDialog', () => {
  it('lists every type with a checkbox and a price whose placeholder is the default, says prices apply to new orders, and passes axe', async () => {
    stubShirts([type(1, 'Mens S T-Shirt'), type(2, 'Mens XXXL T-Shirt', { default_price: '38.00', orders: 3 })], false);
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });

    const checkbox = await within(dialog).findByRole('checkbox', { name: /Mens XXXL T-Shirt/ });
    expect((checkbox as HTMLInputElement).checked).toBe(true);
    const price = within(dialog).getByLabelText('Price for Mens XXXL T-Shirt') as HTMLInputElement;
    expect(price.placeholder).toBe('Default $38.00');
    expect(price.value).toBe('');
    expect(within(dialog).getByText('Prices apply to new orders only.')).toBeTruthy();
    expect(within(dialog).getByText(/offers every shirt type at its default price/)).toBeTruthy();
    expect(within(dialog).getByText('3 orders at this event')).toBeTruthy();
    expect(checkbox.getAttribute('aria-describedby')).toBe('event-shirt-orders-2');
    expect(within(dialog).getByRole('checkbox', { name: 'Mens XXXL T-Shirt' })).toBe(checkbox);
    expect(within(dialog).getByText('2 of 2 selected')).toBeTruthy();
    expect(await axe(dialog)).toHaveNoViolations();
  });

  it('saves the selected types with their prices (empty = default) and confirms with a toast', async () => {
    const { seen } = stubShirts([type(1, 'Mens S T-Shirt'), type(2, 'Mens M T-Shirt'), type(3, 'Mens L T-Shirt')], false);
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });

    await user.click(await within(dialog).findByRole('checkbox', { name: /Mens M T-Shirt/ }));
    await user.type(within(dialog).getByLabelText('Price for Mens S T-Shirt'), '20');
    await user.tab();
    await user.click(within(dialog).getByRole('button', { name: 'Save shirts' }));

    await waitFor(() => expect(putBody(seen)).toEqual({ types: [{ id: 1, price: '20.00' }, { id: 3, price: null }] }));
    expect(await screen.findByText('Shirts saved.')).toBeTruthy();
  });

  it('selects all or none with the helpers, and refuses to save an empty selection', async () => {
    const { seen } = stubShirts([type(1, 'Mens S T-Shirt', { offered: false }), type(2, 'Mens M T-Shirt')], true);
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });
    await within(dialog).findByText('1 of 2 selected');

    await user.click(within(dialog).getByRole('button', { name: 'Select all' }));
    expect(within(dialog).getByText('2 of 2 selected')).toBeTruthy();
    await user.click(within(dialog).getByRole('button', { name: 'Select none' }));
    expect(within(dialog).getByText('0 of 2 selected')).toBeTruthy();
    expect((within(dialog).getByLabelText('Price for Mens S T-Shirt') as HTMLInputElement).disabled).toBe(true);

    await user.click(within(dialog).getByRole('button', { name: 'Save shirts' }));
    expect(within(dialog).getByRole('alert').textContent).toContain('use "Select all"');
    expect(putBody(seen)).toBeUndefined();
  });

  it('warns when a removed type has orders, and reports the kept orders after saving', async () => {
    stubShirts([type(1, 'Mens S T-Shirt'), type(2, 'Mens M T-Shirt', { orders: 2 })], true);
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });

    await user.click(await within(dialog).findByRole('checkbox', { name: /Mens M T-Shirt/ }));
    expect(within(dialog).getByText(/Mens M T-Shirt \(2 orders\)/).textContent).toContain('stay on their invoices');
    await user.click(within(dialog).getByRole('button', { name: 'Save shirts' }));

    expect(await screen.findByText('Shirts saved. 2 orders of removed types keep their price.')).toBeTruthy();
  });

  it('shows a server validation error on the price it belongs to', async () => {
    stubShirts([type(1, 'Mens S T-Shirt'), type(2, 'Mens M T-Shirt')], true, () =>
      jsonResponse(422, { message: 'The price field must not be greater than 9999.99.', errors: { 'types.1.price': ['The price field must not be greater than 9999.99.'] } }),
    );
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });
    await within(dialog).findByText('2 of 2 selected');

    await user.click(within(dialog).getByRole('button', { name: 'Save shirts' }));

    const input = within(dialog).getByLabelText('Price for Mens M T-Shirt');
    await waitFor(() => expect(input.getAttribute('aria-describedby')).toBe('event-shirt-price-2-error'));
    expect(within(dialog).getByText('The price field must not be greater than 9999.99.')).toBeTruthy();
    expect(within(dialog).getByRole('alert').textContent).toContain('Fix the highlighted prices first.');
  });

  it('blocks an invalid price typed in the field before sending', async () => {
    const { seen } = stubShirts([type(1, 'Mens S T-Shirt')], true);
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for Spring Show' });

    await user.type(await within(dialog).findByLabelText('Price for Mens S T-Shirt'), 'abc');
    await user.tab();
    await user.click(within(dialog).getByRole('button', { name: 'Save shirts' }));

    expect(within(dialog).getByText('Enter an amount like 12.50.')).toBeTruthy();
    expect(putBody(seen)).toBeUndefined();
  });
});
