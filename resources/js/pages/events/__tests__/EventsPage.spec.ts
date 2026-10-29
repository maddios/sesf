import { describe, it, expect } from 'vitest';
import { screen, within, fireEvent } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import EventsPage from '../EventsPage.vue';
import { jsonResponse, stubFetch, type RouteHandler } from '../../../test/helpers';
import { CAR_SHOW_RAW, renderWithEventRouter } from './eventFixtures';

const CURRENT = 'GET /events/car-shows?is_active=1';
const PAST = 'GET /events/car-shows?is_active=0';

const OPEN_SHOW = {
  ...CAR_SHOW_RAW,
  id: 37,
  name: 'Southeast Sharkfest 2027',
  shortDescription: 'Coming next spring.',
  shortDescriptionHtml: 'Coming <strong>next</strong> spring.',
  profileURL: '#/SoutheastSharkfest2027/e37',
  ended: false,
  regOpen: true,
  primaryLocation: null,
};

async function renderList(handlers: Record<string, RouteHandler>) {
  stubFetch(handlers);
  return renderWithEventRouter(EventsPage, {}, '/events');
}

describe('EventsPage', () => {
  it('lists current and past shows in their own sections', async () => {
    await renderList({
      [CURRENT]: () => jsonResponse(200, [OPEN_SHOW]),
      [PAST]: () => jsonResponse(200, [CAR_SHOW_RAW]),
    });
    const current = screen.getByRole('region', { name: 'Current Shows' });
    expect(await within(current).findByRole('heading', { level: 3, name: 'Southeast Sharkfest 2027' })).toBeTruthy();
    const summary = current.querySelector('.event-summary');
    expect(summary?.textContent).toBe('Coming next spring.');
    expect(summary?.querySelector('strong')?.textContent).toBe('next');
    expect(within(current).getByText('Open')).toBeTruthy();

    const past = screen.getByRole('region', { name: 'Past Shows' });
    expect(await within(past).findByRole('heading', { level: 3, name: /southeast sharkfest 2026/i })).toBeTruthy();
    expect(within(past).getByText('(Ended)')).toBeTruthy();
    expect(screen.getAllByRole('link', { name: /event info for/i })).toHaveLength(2);
  });

  it('shows the registration window like legacy, omitting unset dates', async () => {
    await renderList({
      [CURRENT]: () => jsonResponse(200, [{ ...OPEN_SHOW, date_active: null, date_registration_deactivation: null }]),
      [PAST]: () => jsonResponse(200, [CAR_SHOW_RAW]),
    });
    const past = screen.getByRole('region', { name: 'Past Shows' });
    expect(await within(past).findByText('Registration opens')).toBeTruthy();
    expect(within(past).getByText('Jan 15, 2026 9:30:00 AM')).toBeTruthy();
    expect(within(past).getByText('Closes')).toBeTruthy();
    expect(within(past).getByText('Apr 20, 2026 11:59:59 PM')).toBeTruthy();
    const current = screen.getByRole('region', { name: 'Current Shows' });
    await within(current).findByRole('heading', { level: 3, name: 'Southeast Sharkfest 2027' });
    expect(within(current).queryByText('Registration opens')).toBeNull();
  });

  it('shows empty messages when there are no shows', async () => {
    await renderList({ [CURRENT]: () => jsonResponse(200, []), [PAST]: () => jsonResponse(200, []) });
    expect(await screen.findByText('No current shows right now. Check back soon.')).toBeTruthy();
    expect(screen.getByText('No past shows to show yet.')).toBeTruthy();
  });

  it('shows a per-section error and retries only that section', async () => {
    let pastCalls = 0;
    await renderList({
      [CURRENT]: () => jsonResponse(200, [OPEN_SHOW]),
      [PAST]: () => {
        pastCalls += 1;
        return pastCalls === 1 ? jsonResponse(500, {}) : jsonResponse(200, [CAR_SHOW_RAW]);
      },
    });
    const past = screen.getByRole('region', { name: 'Past Shows' });
    const alert = await within(past).findByRole('alert');
    expect(within(alert).getByText('The past shows could not be loaded right now.')).toBeTruthy();
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await within(past).findByRole('heading', { level: 3, name: /southeast sharkfest 2026/i })).toBeTruthy();
    expect(screen.getByRole('region', { name: 'Current Shows' }).textContent).toContain('Southeast Sharkfest 2027');
  });

  it('has no accessibility violations once loaded', async () => {
    const { container } = await renderList({
      [CURRENT]: () => jsonResponse(200, [OPEN_SHOW]),
      [PAST]: () => jsonResponse(200, [CAR_SHOW_RAW]),
    });
    await screen.findByRole('heading', { level: 3, name: 'Southeast Sharkfest 2027' });
    await screen.findByRole('heading', { level: 3, name: /southeast sharkfest 2026/i });
    expect(await axe(container)).toHaveNoViolations();
  });
});
