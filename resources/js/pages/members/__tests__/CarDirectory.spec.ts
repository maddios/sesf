import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import CarDirectory from '../CarDirectory.vue';
import { CAR_FILTERS, CAR_ROW, fetchWith, jsonResponse, mountWithRouter, pageBody, requested } from './fixtures';

function carApi(pages: (url: URL) => unknown = () => pageBody([CAR_ROW])) {
  return fetchWith((url) => {
    if (url.pathname === '/cars/list-filters') {
      return jsonResponse(200, CAR_FILTERS);
    }
    if (url.pathname === '/cars/list') {
      return jsonResponse(200, pages(url));
    }
    return jsonResponse(404, { message: 'Not found.' });
  });
}

describe('CarDirectory (standalone)', () => {
  it('renders car cards linking to the car profile with owner names', async () => {
    carApi();
    const { container } = await mountWithRouter(CarDirectory, { path: '/cars' });

    expect(await screen.findByRole('heading', { level: 1, name: 'Cars' })).toBeTruthy();
    const link = await screen.findByRole('link', { name: 'Sharky' });
    expect(link.getAttribute('href')).toBe('/car/78');
    expect(screen.getByText('Owner: Shawn B')).toBeTruthy();
    expect(screen.getByText('1 car')).toBeTruthy();
    expect(container.querySelector('ul.grid')?.className).toContain('lg:grid-cols-4');
  });

  it('shows the show and body selects and applies a body change to the query', async () => {
    const user = userEvent.setup();
    const fn = carApi();
    const { router } = await mountWithRouter(CarDirectory, { path: '/cars' });
    await screen.findByRole('link', { name: 'Sharky' });

    expect(screen.getByLabelText('Attended show')).toBeTruthy();
    await user.selectOptions(screen.getByLabelText('Body style'), '2');
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ body_id: '2' }));
    await waitFor(() => expect(requested(fn)).toContain('/cars/list?limit=20&page=1&body_id=2'));
  });

  it('reads show_id and body_id from the query and passes them to the API', async () => {
    const fn = carApi();
    await mountWithRouter(CarDirectory, { path: '/cars?show_id=36&body_id=2&page=2' });
    await screen.findByRole('link', { name: 'Sharky' });
    expect(requested(fn)).toContain('/cars/list?limit=20&page=2&show_id=36&body_id=2');
    expect((screen.getByLabelText('Body style') as HTMLSelectElement).value).toBe('2');
  });

  it('pages through the list', async () => {
    const user = userEvent.setup();
    const fn = carApi((url) => pageBody([CAR_ROW], { last: 2, page: Number(url.searchParams.get('page')), total: 25 }));
    const { router } = await mountWithRouter(CarDirectory, { path: '/cars' });
    await screen.findByRole('link', { name: 'Sharky' });

    await user.click(within(screen.getByRole('navigation', { name: 'Car list pages' })).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ page: '2' }));
    await waitFor(() => expect(requested(fn)).toContain('/cars/list?limit=20&page=2'));
  });

  it('shows the empty state and falls back to a generic image for a car without a photo', async () => {
    carApi(() => pageBody([{ ...CAR_ROW, imageURL: '', has_image: false, user: null, nickname: '' }]));
    await mountWithRouter(CarDirectory, { path: '/cars' });
    const link = await screen.findByRole('link', { name: '1987 M635CSi' });
    expect(link.getAttribute('href')).toBe('/car/78');
    expect(screen.queryByText(/Owner:/)).toBeNull();
  });

  it('renders the empty state', async () => {
    carApi(() => pageBody([]));
    await mountWithRouter(CarDirectory, { path: '/cars' });
    expect(await screen.findByText('No cars match these filters.')).toBeTruthy();
  });

  it('shows an error alert when the list fails', async () => {
    fetchWith((url) => (url.pathname === '/cars/list-filters' ? jsonResponse(200, CAR_FILTERS) : jsonResponse(500, {})));
    await mountWithRouter(CarDirectory, { path: '/cars' });
    expect(await screen.findByText('The car list could not be loaded.')).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    carApi();
    const { container } = await mountWithRouter(CarDirectory, { path: '/cars' });
    await screen.findByRole('link', { name: 'Sharky' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('CarDirectory (embedded in an event tab)', () => {
  it('uses the fixed show, no heading, 5 columns and 25 per page', async () => {
    const fn = carApi();
    const { container, router } = await mountWithRouter(CarDirectory, {
      path: '/SoutheastSharkfest2026/e36',
      props: { showId: 36, embedded: true },
    });
    await screen.findByRole('link', { name: 'Sharky' });

    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    expect(screen.queryByLabelText('Attended show')).toBeNull();
    expect(container.querySelector('ul.grid')?.className).toContain('lg:grid-cols-5');
    expect(requested(fn)).toContain('/cars/list?limit=25&page=1&show_id=36');
    expect(router.currentRoute.value.query).toEqual({});
  });
});
