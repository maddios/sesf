import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import TshirtsPage from '../TshirtsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { jsonResponse, renderAt } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { useToast } from '../../../../composables/admin/useToast';
import type { ShirtInventory } from '../tshirts';

const Page = defineComponent({ render: () => [h(TshirtsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function inventory(overrides: Partial<ShirtInventory> = {}): ShirtInventory {
  return {
    show: { id: 4, name: 'Spring Show' },
    types: [
      { id: 1, name: 'Mens Small', price_cents: 2000, offered: true, stock: 5, ordered: 2, handed_out: 1, adhoc_sold: 0, remaining: 4, available_for_adhoc: 3 },
      { id: 2, name: 'Mens Medium', price_cents: 2000, offered: true, stock: 10, ordered: 4, handed_out: 2, adhoc_sold: 1, remaining: 7, available_for_adhoc: 5 },
    ],
    registrations: [
      {
        id: 21,
        invoice_id: 7,
        primary_name: 'Ada Lovelace',
        lines: [
          { id: 91, type_id: 2, type_name: 'Mens Medium', handed_out: false },
          { id: 92, type_id: 1, type_name: 'Mens Small', handed_out: true },
        ],
        left_to_hand_out: 1,
        all_handed_out: false,
      },
      {
        id: 22,
        invoice_id: 9,
        primary_name: 'Grace Hopper',
        lines: [{ id: 93, type_id: 2, type_name: 'Mens Medium', handed_out: true }],
        left_to_hand_out: 0,
        all_handed_out: true,
      },
    ],
    adhoc_sales: [{ id: 31, created_at: '2026-05-01 10:00:00', sold_by: 'Ada Lovelace', items: [{ type_id: 2, type_name: 'Mens Medium', count: 1 }] }],
    ...overrides,
  };
}

function stubShirts(data: ShirtInventory = inventory(), extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/shirts/inventory') return jsonResponse(200, { data });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  vi.restoreAllMocks();
});

describe('TshirtsPage', () => {
  it('shows the tally, the registrations and the sales for the event, and passes axe', async () => {
    const { seen } = stubShirts();
    const { container } = await renderAt(Page, '/admin/tshirts?show_id=4');

    const chips = await screen.findByRole('list', { name: 'Shirts available' });
    expect(within(chips).getByText('Mens Medium: 7 available')).toBeTruthy();
    const table = screen.getByRole('table', { name: 'Registrations with shirts' });
    expect(within(table).getByText('Partial')).toBeTruthy();
    expect(within(table).getByText('1 left to hand out')).toBeTruthy();
    // Fully handed out registrations are hidden by default, as in the legacy page.
    expect(within(table).queryByText('Grace Hopper')).toBeNull();
    expect(screen.getByText('Showing:').parentElement?.textContent).toContain('1');
    expect(within(screen.getByRole('table', { name: 'Ad-hoc sales' })).getByText('Mens Medium')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/shirts/inventory')!.params.get('show_id')).toBe('4');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('hands a shirt out from the registrant dialog and re-reads the counts', async () => {
    const { seen } = stubShirts(inventory(), (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/shirts/91/handed-out') return jsonResponse(200, { data: { id: 91, registration_id: 21, handed_out: true } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Shirts for #7 Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for registrant' });
    expect((within(dialog).getByRole('checkbox', { name: 'Handed out: Mens Small' }) as HTMLInputElement).checked).toBe(true);
    await user.click(within(dialog).getByRole('checkbox', { name: 'Handed out: Mens Medium' }));

    await waitFor(() => expect(seen.find((r) => r.method === 'POST' && r.path.endsWith('/handed-out'))?.body).toEqual({ handed_out: true }));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/shirts/inventory').length).toBeGreaterThan(1));
  });

  it('keeps the switch as stored and shows the reason when a hand-out fails', async () => {
    stubShirts(inventory(), (request) => {
      if (request.method === 'POST' && request.path.endsWith('/handed-out')) return jsonResponse(409, { message: 'Shirt already handed out.' });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Shirts for #7 Ada Lovelace' }));
    const dialog = await screen.findByRole('dialog', { name: 'Shirts for registrant' });
    const box = within(dialog).getByRole('checkbox', { name: 'Handed out: Mens Medium' }) as HTMLInputElement;
    await user.click(box);
    expect(await within(dialog).findByText('Shirt already handed out.')).toBeTruthy();
    expect(box.checked).toBe(false);
  });

  it('saves only the stock counts that changed', async () => {
    const { seen } = stubShirts(inventory(), (request) => {
      if (request.method === 'PUT') return jsonResponse(200, { data: inventory() });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Set shirt inventory' }));
    const dialog = await screen.findByRole('dialog', { name: 'Shirt inventory for this event' });
    await user.click(within(dialog).getByRole('button', { name: 'More Mens Medium on hand' }));
    await user.click(within(dialog).getByRole('button', { name: 'Save inventory' }));

    await waitFor(() => expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({ stock: { 2: 11 } }));
  });

  it('records a walk-up sale one row per shirt, limited to what is available', async () => {
    const { seen } = stubShirts(inventory(), (request) => {
      if (request.method === 'POST' && request.path === '/admin-api/shirts/inventory/4/sales') return jsonResponse(201, { data: inventory() });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Sell shirt' }));
    const add = await screen.findByRole('button', { name: 'Add one Mens Medium to the sale' });
    for (let i = 0; i < 6; i++) await user.click(add);
    expect(screen.getByLabelText('Mens Medium in sale').textContent).toBe('5');

    await user.click(screen.getByRole('button', { name: 'Record sale (5)' }));
    await waitFor(() => expect(seen.find((r) => r.method === 'POST' && r.path.endsWith('/sales'))?.body).toEqual({ type_ids: [2, 2, 2, 2, 2] }));
  });

  it('shows the server reason when a sale is refused', async () => {
    stubShirts(inventory(), (request) => {
      if (request.method === 'POST' && request.path.endsWith('/sales')) {
        return jsonResponse(422, { message: 'Please correct the highlighted fields.', errors: { type_ids: ['Mens Medium has only 1 available.'] } });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Sell shirt' }));
    await user.click(await screen.findByRole('button', { name: 'Add one Mens Medium to the sale' }));
    await user.click(screen.getByRole('button', { name: 'Record sale (1)' }));

    expect(await screen.findByText('Mens Medium has only 1 available.')).toBeTruthy();
  });

  it('asks before deleting a sale and then deletes it', async () => {
    const { seen } = stubShirts(inventory(), (request) => {
      if (request.method === 'DELETE') return new Response(null, { status: 204 });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Delete sale 31' }));

    const dialog = await screen.findByRole('dialog', { name: 'Delete sale' });
    expect(seen.some((r) => r.method === 'DELETE')).toBe(false);
    await user.click(within(dialog).getByRole('button', { name: 'Delete sale' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE' && r.path === '/admin-api/shirts/sales/31')).toBe(true));
  });

  it('warns when ordered plus ad hoc sold exceeds the stock on hand', async () => {
    stubShirts(
      inventory({
        types: [{ id: 2, name: 'Mens Medium', price_cents: 2000, offered: true, stock: 3, ordered: 4, handed_out: 0, adhoc_sold: 1, remaining: 3, available_for_adhoc: -2 }],
      }),
    );
    await renderAt(Page, '/admin/tshirts?show_id=4');

    expect(await screen.findByText(/Inventory shortfall/)).toBeTruthy();
    expect(screen.getByText(/2 short/)).toBeTruthy();
  });

  it('shows the fully handed out registrations when the filter is turned off', async () => {
    stubShirts();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    const table = await screen.findByRole('table', { name: 'Registrations with shirts' });
    expect(within(table).queryByText('Grace Hopper')).toBeNull();

    await user.click(screen.getByLabelText('Hide fully handed out'));
    expect(within(table).getByText('Grace Hopper')).toBeTruthy();
    await user.type(screen.getByLabelText('Narrow by name or registration number'), '#9');
    expect(within(table).queryByText('Ada Lovelace')).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Clear search' }));
    expect(within(table).getByText('Ada Lovelace')).toBeTruthy();
  });

  it('links the printable checklist (new tab) and prints the sheet', async () => {
    stubShirts();
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
    const user = userEvent.setup();
    await renderAt(Page, '/admin/tshirts?show_id=4');
    expect((await screen.findByRole('link', { name: 'Printable shirt checklist' })).getAttribute('href')).toBe('#/admin/tshirts?show_id=4&print=1');
  
    const printed = await renderAt(Page, '/admin/tshirts?show_id=4&print=1');
    const sheet = await screen.findByRole('region', { name: 'Printable shirt checklist' });
    expect(within(sheet).getByText('T-shirt pickup — Spring Show')).toBeTruthy();
    expect(within(sheet).getByText('Inventory totals')).toBeTruthy();
    await user.click(within(sheet).getByRole('button', { name: 'Print' }));
    expect(print).toHaveBeenCalledOnce();
    printed.unmount();
  });

  it('asks for an event first', async () => {
    stubShirts();
    const { container } = await renderAt(Page, '/admin/tshirts');
    expect(await screen.findByText('Choose an event to see its T-shirts.')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });
});
