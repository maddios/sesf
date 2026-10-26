import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import CarsPage from '../CarsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { imageSrc, type Car } from '../cars';

function car(overrides: Partial<Car> = {}): Car {
  return {
    id: 7,
    user_id: 1,
    owner_name: 'Ada Lovelace',
    owner_email: 'ada@example.test',
    type_id: 1,
    type_name: 'Car',
    body_id: 2,
    body_name: 'Coupe',
    year: '1969',
    model: 'Camaro',
    nickname: 'Blue Bolt',
    VIN: null,
    is_confirmed: false,
    has_image: true,
    image_url: 'images/cars/7/profile.jpg?v=1',
    registrations_count: 0,
    in_use: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const OPTIONS = {
  types: [{ id: 1, label: 'Car' }, { id: 2, label: 'Motorcycle' }],
  bodies: [{ id: 2, label: 'Coupe' }],
};

const Page = defineComponent({ render: () => [h(CarsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

function stubCars(rows: Car[] = [car(), car({ id: 8, model: 'Mustang', is_confirmed: true, in_use: true, registrations_count: 2, has_image: false })], extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/cars') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/cars/options') return jsonResponse(200, { data: OPTIONS });
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Cars' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('CarsPage', () => {
  it('lists cars with the owner, body and confirmation, and passes axe', async () => {
    const { seen } = stubCars();
    const { container } = await renderAt(Page, '/admin/cars');
    expect(await within(table()).findByText('1969 Camaro')).toBeTruthy();
    expect(within(table()).getAllByText('Ada Lovelace').length).toBeGreaterThan(0);
    expect(within(table()).getAllByText('Coupe').length).toBeGreaterThan(0);
    expect(within(table()).getByText('Unconfirmed')).toBeTruthy();
    expect(within(table()).getByRole('button', { name: 'Delete car 8' }).hasAttribute('disabled')).toBe(true);
    expect(seen.some((r) => r.path === '/admin-api/cars/options')).toBe(true);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('confirms a car and reloads the list', async () => {
    const { seen } = stubCars(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/cars/7/confirm' ? jsonResponse(200, { data: car({ is_confirmed: true }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/cars');
    await user.click(await within(table()).findByRole('button', { name: 'Confirm car 7' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/cars/7/confirm')).toBe(true));
    expect(await screen.findByText('Car confirmed.')).toBeTruthy();
  });

  it('saves the owner through the member lookup and the body/type selects', async () => {
    const { seen } = stubCars(undefined, (r) => {
      if (r.method === 'PUT' && r.path === '/admin-api/cars/7') return jsonResponse(200, { data: car({ model: 'Chevelle' }) });
      if (r.path === '/admin-api/users/lookup') return jsonResponse(200, { data: [{ id: 3, label: 'Bo Gone <bo@example.test>' }] });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/cars');
    await user.click(await within(table()).findByRole('button', { name: 'Edit car 7' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit car 7' });
    await user.type(within(dialog).getByRole('combobox', { name: 'Owner' }), 'bo');
    await user.click(await screen.findByRole('option', { name: 'Bo Gone <bo@example.test>' }));
    await user.clear(within(dialog).getByLabelText('Model'));
    await user.type(within(dialog).getByLabelText('Model'), 'Chevelle');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'PUT')).toBe(true));
    expect(seen.find((r) => r.method === 'PUT')?.body).toMatchObject({ user_id: 3, type_id: 1, body_id: 2, model: 'Chevelle', nickname: 'Blue Bolt' });
  });

  it('deletes an unused car only after the typed confirmation', async () => {
    const { seen } = stubCars(undefined, (r) => (r.method === 'DELETE' && r.path === '/admin-api/cars/7' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/cars');
    await user.click(await within(table()).findByRole('button', { name: 'Delete car 7' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete car' });
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
  });
});

describe('car helpers', () => {
  it('builds image URLs from the API path', () => {
    expect(imageSrc(car())).toBe('/images/cars/7/profile.jpg?v=1');
    expect(imageSrc(car({ has_image: false }))).toBeNull();
  });
});
