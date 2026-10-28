import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import LocationsPage from '../LocationsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { Location } from '../locations';

function location(overrides: Partial<Location> = {}): Location {
  return {
    id: 1,
    show_id: 4,
    show_name: 'Spring Show',
    name: 'Main lot',
    description: null,
    is_primary: true,
    sort_order: 1,
    address1: '12 Main St',
    address2: null,
    city: 'Raleigh',
    state: 'NC',
    zip: '27601',
    has_coordinates: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const Page = defineComponent({ render: () => [h(LocationsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const ROWS = [location(), location({ id: 2, name: 'Overflow field', is_primary: false, sort_order: 2, address1: '9 Field Rd', city: 'Cary', zip: '27511' })];

function stubLocations(rows: Location[] = ROWS, extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/locations') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('LocationsPage', () => {
  it('asks for an event first and does not fetch venues', async () => {
    const { seen } = stubLocations();
    const { container } = await renderAt(Page, '/admin/locations');
    expect(await screen.findByText('Choose an event to see and arrange its locations.')).toBeTruthy();
    expect(seen.some((r) => r.path === '/admin-api/locations')).toBe(false);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('lists the event venues in position order with addresses, and passes axe', async () => {
    const { seen } = stubLocations();
    const { container } = await renderAt(Page, '/admin/locations?f.show_id=4');
    const list = await screen.findByRole('list', { name: 'Locations' });
    expect(await within(list).findByText('Main lot')).toBeTruthy();
    expect(within(list).getByText('12 Main St, Raleigh, NC 27601')).toBeTruthy();
    expect(within(list).getByText('Primary')).toBeTruthy();
    expect(seen.find((r) => r.path === '/admin-api/locations')?.params.get('filter[show_id]')).toBe('4');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('saves a new order for the event when a venue is moved', async () => {
    const { seen } = stubLocations(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/locations/reorder' ? jsonResponse(200, { data: { show_id: 4, ids: [2, 1] } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/locations?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Move Main lot down' }));

    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/locations/reorder')).toBe(true));
    expect(seen.find((r) => r.path === '/admin-api/locations/reorder')?.body).toEqual({ show_id: 4, ids: [2, 1] });
    expect(await screen.findByText('Location order saved.')).toBeTruthy();
  });

  it('creates a venue in the chosen event', async () => {
    const { seen } = stubLocations(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/locations' ? jsonResponse(201, { data: location({ id: 3, name: 'Hall' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/locations?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'New location' }));
    const dialog = await screen.findByRole('dialog', { name: 'New location' });
    await user.type(within(dialog).getByLabelText('Name (required)'), 'Hall');
    await user.type(within(dialog).getByLabelText('Street (required)'), '1 Hall Way');
    await user.type(within(dialog).getByLabelText('City (required)'), 'Apex');
    await user.type(within(dialog).getByLabelText('State (required)'), 'NC');
    await user.type(within(dialog).getByLabelText('Zip (required)'), '27502');
    await user.click(within(dialog).getByRole('button', { name: 'Create location' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/locations')?.body).toEqual({
      show_id: 4,
      name: 'Hall',
      description: null,
      address1: '1 Hall Way',
      address2: null,
      city: 'Apex',
      state: 'NC',
      zip: '27502',
      is_primary: false,
    });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Location “Hall” created.');
  });

  it('maps a server address error onto the field', async () => {
    stubLocations(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/locations/1'
        ? jsonResponse(422, { message: 'Invalid.', errors: { address1: ['The address1 field must not be greater than 60 characters.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/locations?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Edit Main lot' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Main lot' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('The address1 field must not be greater than 60 characters.')).toBeTruthy();
  });

  it('deletes only after the typed confirmation', async () => {
    const { seen } = stubLocations(ROWS, (r) => (r.method === 'DELETE' && r.path === '/admin-api/locations/2' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/locations?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Delete Overflow field' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete location' });
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Location “Overflow field” deleted.')).toBeTruthy();
  });
});
