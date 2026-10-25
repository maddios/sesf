import { describe, it, expect, afterEach } from 'vitest';
import { screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import CarProfilePage from '../CarProfilePage.vue';
import { CAR_PROFILE_OWNER, CAR_PROFILE_PUBLIC, MEMBER_USER, fetchWith, jsonResponse, mountWithRouter } from '../../members/__tests__/fixtures';

afterEach(() => {
  document.title = '';
});

function carApi(body: unknown, status = 200) {
  return fetchWith((url) => {
    if (url.pathname === '/car/profile') {
      return jsonResponse(status, body);
    }
    return jsonResponse(404, {});
  });
}

describe('CarProfilePage', () => {
  it('shows the public view: title, body and type, owner link and shows', async () => {
    const fn = carApi(CAR_PROFILE_PUBLIC);
    const { container } = await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 } });

    expect(await screen.findByRole('heading', { level: 1, name: '1987 M635CSi' })).toBeTruthy();
    expect(fn.mock.calls[0]?.[0]).toBe('/car/profile?car_id=78');
    expect(document.title).toBe('1987 M635CSi | Southeast Sharkfest');
    expect(screen.getByText(/Body: Coupe/)).toBeTruthy();
    expect(screen.getByText(/Type: Sport/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Shawn B' }).getAttribute('href')).toBe('/ShawnB/u12');
    expect(screen.getByRole('link', { name: 'Southeast Sharkfest 2026' }).getAttribute('href')).toBe('/SoutheastSharkfest2026/e36');
    expect(screen.getByText('(Apr 2026)')).toBeTruthy();
    // Visitors see no edit link and no judging button.
    expect(screen.queryByRole('link', { name: 'Edit car' })).toBeNull();
    expect(screen.queryByRole('link', { name: /Judging results/ })).toBeNull();
    expect(container.querySelector('img')?.getAttribute('alt')).toBe('Photo of 1987 M635CSi');
  });

  it('shows the owner view with an edit link and a judging button when the server sends one', async () => {
    carApi(CAR_PROFILE_OWNER);
    await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 }, user: MEMBER_USER });

    expect(await screen.findByRole('heading', { level: 1, name: 'Sharky' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Edit car' }).getAttribute('href')).toBe('/car/78/settings');
    const judging = screen.getByRole('link', { name: 'Judging results for Southeast Sharkfest 2026' });
    expect(judging.getAttribute('href')).toBe('#/SoutheastSharkfest2026/judging');
    expect(screen.getByText(/Body: Coupe/)).toBeTruthy();
  });

  it('does not render an unsafe judging link', async () => {
    carApi({
      ...CAR_PROFILE_OWNER,
      shows: [{ name: 'Show', shortDate: '', profileURL: '', judgingLink: 'javascript:alert(1)' }],
    });
    await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 }, user: MEMBER_USER });
    await screen.findByRole('heading', { level: 1, name: 'Sharky' });
    expect(screen.queryByRole('link', { name: /Judging results/ })).toBeNull();
    expect(screen.getByText('Show')).toBeTruthy();
  });

  it('shows an empty shows message', async () => {
    carApi({ ...CAR_PROFILE_PUBLIC, shows: [], body: { name: '' }, type: { type: '' }, user: null });
    await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 } });
    expect(await screen.findByText('This car has not attended a show yet.')).toBeTruthy();
    expect(screen.queryByText(/Owner:/)).toBeNull();
    expect(screen.queryByText(/Body:/)).toBeNull();
  });

  it('shows the not-found state for an unknown car', async () => {
    carApi({ message: 'Not found.' }, 404);
    await mountWithRouter(CarProfilePage, { path: '/car/999', props: { carId: 999 } });
    expect(await screen.findByRole('heading', { level: 1, name: 'Car not found' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Browse all cars' }).getAttribute('href')).toBe('/cars');
    expect(document.title).toBe('Car not found | Southeast Sharkfest');
  });

  it('shows an error with retry for other failures', async () => {
    const user = userEvent.setup();
    let fail = true;
    fetchWith((url) => {
      if (url.pathname !== '/car/profile') {
        return jsonResponse(404, {});
      }
      return fail ? jsonResponse(500, {}) : jsonResponse(200, CAR_PROFILE_PUBLIC);
    });
    await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 } });
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('The car profile could not be loaded.')).toBeTruthy();
    fail = false;
    await user.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 1, name: '1987 M635CSi' })).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    carApi(CAR_PROFILE_OWNER);
    const { container } = await mountWithRouter(CarProfilePage, { path: '/car/78', props: { carId: 78 }, user: MEMBER_USER });
    await screen.findByRole('heading', { level: 1, name: 'Sharky' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
