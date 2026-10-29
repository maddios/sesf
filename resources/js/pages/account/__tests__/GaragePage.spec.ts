import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { jsonResponse, stubFetch, callsTo } from '../../../test/helpers';
import { mountAccount, OWNER_ID } from '../../../components/account/__tests__/harness';
import GaragePage from '../GaragePage.vue';

const CARS = [
  { id: 1, nickname: 'Red Bug', year: '1971', model: 'Beetle', imageURL: 'images/cars/1.jpg', profileURL: '#/car/1' },
  { id: 2, nickname: '', year: '1957', model: 'Bel Air', imageURL: '' },
];

const props = { memberName: 'Fan', memberId: OWNER_ID };
const PATH = '/Fan/u7/cars';

describe('GaragePage', () => {
  it('lists the garage with edit, sold and add controls', async () => {
    stubFetch({ 'GET /member/cars?user_id=7': () => jsonResponse(200, CARS) });
    await mountAccount(GaragePage, { props, path: PATH });
    expect(await screen.findByRole('heading', { name: 'Red Bug' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: '1957 Bel Air' })).toBeTruthy();
    const edit = screen.getAllByRole('link', { name: /Edit/ })[0] as HTMLAnchorElement;
    expect(edit.getAttribute('href')).toBe('/car/1/settings');
    expect(screen.getByRole('link', { name: 'Add car' }).getAttribute('href')).toBe('/car/new');
    expect(screen.getAllByRole('button', { name: /Mark as sold/ })).toHaveLength(2);
  });

  it('shows the empty state when there are no cars', async () => {
    stubFetch({ 'GET /member/cars?user_id=7': () => jsonResponse(200, []) });
    await mountAccount(GaragePage, { props, path: PATH });
    expect(await screen.findByText('No cars in the garage yet.')).toBeTruthy();
  });

  it('offers a retry when the garage fails to load', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    stubFetch({
      'GET /member/cars?user_id=7': () => {
        attempt += 1;
        return attempt === 1 ? new Response('', { status: 500 }) : jsonResponse(200, CARS);
      },
    });
    await mountAccount(GaragePage, { props, path: PATH });
    expect(await screen.findByText('Your garage could not be loaded.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { name: 'Red Bug' })).toBeTruthy();
  });

  it('marks a car as sold after confirmation and removes it from the list', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({
      'GET /member/cars?user_id=7': () => jsonResponse(200, CARS),
      'POST /member/sell-car': () => new Response('', { status: 200 }),
    });
    await mountAccount(GaragePage, { props, path: PATH });
    await screen.findByRole('heading', { name: 'Red Bug' });
    await user.click(screen.getAllByRole('button', { name: /Mark as sold/ })[0] as HTMLElement);
    const dialog = await screen.findByRole('dialog', { name: 'Mark this car as sold?' });
    expect(dialog).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/sell-car')).toHaveLength(0);

    await user.click(screen.getByRole('button', { name: 'Mark as sold' }));
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Red Bug' })).toBeNull());
    expect(callsTo(fetchMock, 'POST /member/sell-car')[0]).toEqual({ id: 1 });
    expect(screen.getByRole('heading', { name: '1957 Bel Air' })).toBeTruthy();
  });

  it('keeps the car and shows an error when the sale fails, and can cancel', async () => {
    const user = userEvent.setup();
    stubFetch({
      'GET /member/cars?user_id=7': () => jsonResponse(200, CARS),
      'POST /member/sell-car': () => jsonResponse(400, ['Car is already sold']),
    });
    await mountAccount(GaragePage, { props, path: PATH });
    await screen.findByRole('heading', { name: 'Red Bug' });
    await user.click(screen.getAllByRole('button', { name: /Mark as sold/ })[0] as HTMLElement);
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: 'Mark as sold' }));
    expect(await screen.findByText('Car is already sold')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Red Bug' })).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Keep car' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('uses a generic sale error when the server gives no message', async () => {
    const user = userEvent.setup();
    stubFetch({
      'GET /member/cars?user_id=7': () => jsonResponse(200, CARS),
      'POST /member/sell-car': () => new Response('', { status: 500 }),
    });
    await mountAccount(GaragePage, { props, path: PATH });
    await screen.findByRole('heading', { name: 'Red Bug' });
    await user.click(screen.getAllByRole('button', { name: /Mark as sold/ })[0] as HTMLElement);
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: 'Mark as sold' }));
    expect(await screen.findByText('The car could not be marked as sold. Please try again.')).toBeTruthy();
  });

  it('shows another member\'s garage read-only to an admin, with no edit or sale controls', async () => {
    stubFetch({ 'GET /member/cars?user_id=9': () => jsonResponse(200, CARS) });
    await mountAccount(GaragePage, { props: { memberName: 'Other', memberId: 9 }, admin: true, path: '/Other/u9/cars' });
    expect(await screen.findByRole('heading', { name: 'Red Bug' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: /Edit/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Mark as sold/ })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Add car' })).toBeNull();
  });
});
