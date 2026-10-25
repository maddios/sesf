import { describe, it, expect, vi } from 'vitest';
import { screen, within, waitFor, fireEvent } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { jsonResponse, type RouteHandler } from '../../../test/helpers';
import { AGENDA_RAW, JUDGING_RAW, LOCATIONS_RAW, SCHEDULE_PDF, eventHandlers, renderEventRoute } from './eventFixtures';

const BASE = '/SoutheastSharkfest2026/e36';
const AGENDA1 = 'GET /event/agenda?show_id=36&type_id=1';
const AGENDA2 = 'GET /event/agenda?show_id=36&type_id=2';
const SCHEDULE = 'GET /event/schedule?show_id=36';
const LOCATIONS = 'GET /event/locations?show_id=36';
const SPONSORS = 'GET /event/sponsors?show_id=36';

describe('EventInfoPage', () => {
  it('renders agenda sections, show dates and the register button', async () => {
    await renderEventRoute(BASE);
    expect(await screen.findByRole('heading', { level: 2, name: 'Welcome' })).toBeTruthy();
    expect(screen.getByText('fans')).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2, name: 'Saturday' })).toBeTruthy();
    expect(screen.getByText('Check-in')).toBeTruthy();
    expect(screen.getByText('Apr 30, 2026')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Register now' }).getAttribute('href')).toBe('/SoutheastSharkfest2026/e36/register');
  });

  it('puts the sponsor logos beside the second agenda item and skips unsafe sponsor links', async () => {
    await renderEventRoute(BASE);
    const aside = await screen.findByRole('complementary', { name: 'Sponsors' });
    expect(within(aside).getByRole('link', { name: /odometer gears/i }).getAttribute('href')).toBe('https://odometer.example/');
    expect(within(aside).getByText('Gold Garage')).toBeTruthy();
    expect(within(aside).queryByRole('link', { name: /gold garage/i })).toBeNull();
  });

  it('shows an empty message when no agenda is posted', async () => {
    await renderEventRoute(BASE, eventHandlers({ [AGENDA1]: () => jsonResponse(200, []) }));
    expect(await screen.findByText('Event details will be posted here soon.')).toBeTruthy();
  });

  it('shows an error with retry when the agenda fails', async () => {
    let calls = 0;
    const handler: RouteHandler = () => {
      calls += 1;
      return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, AGENDA_RAW);
    };
    await renderEventRoute(BASE, eventHandlers({ [AGENDA1]: handler }));
    const alert = await screen.findByRole('alert');
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 2, name: 'Welcome' })).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    const { container } = await renderEventRoute(BASE);
    await screen.findByRole('heading', { level: 2, name: 'Welcome' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('EventJudgingPage', () => {
  it('renders the judging agenda', async () => {
    await renderEventRoute(`${BASE}/judging`);
    expect(await screen.findByRole('heading', { level: 2, name: JUDGING_RAW[0]?.name ?? '' })).toBeTruthy();
    expect(screen.getByText('Be fair')).toBeTruthy();
  });

  it('shows an empty message when there are no judging items', async () => {
    await renderEventRoute(`${BASE}/judging`, eventHandlers({ [AGENDA2]: () => jsonResponse(200, []) }));
    expect(await screen.findByText('No judging information has been posted yet.')).toBeTruthy();
  });
});

describe('EventSchedulePage', () => {
  it('embeds a PDF schedule with a download link', async () => {
    const { container } = await renderEventRoute(`${BASE}/schedule`, eventHandlers({ [SCHEDULE]: () => jsonResponse(200, SCHEDULE_PDF) }));
    const object = await waitFor(() => {
      const el = container.querySelector('object');
      expect(el).not.toBeNull();
      return el as HTMLObjectElement;
    });
    expect(object.getAttribute('data')).toBe('/images/shows/schedule36.pdf');
    expect(object.getAttribute('type')).toBe('application/pdf');
    expect(screen.getByRole('link', { name: /download the schedule \(pdf\)/i }).getAttribute('href')).toBe('/images/shows/schedule36.pdf');
  });

  it('renders an HTML schedule through SafeHtml', async () => {
    await renderEventRoute(`${BASE}/schedule`);
    expect(await screen.findByText('Doors open at 8am')).toBeTruthy();
  });

  it('shows an empty message when nothing is posted', async () => {
    await renderEventRoute(`${BASE}/schedule`, eventHandlers({ [SCHEDULE]: () => jsonResponse(200, { schedule: '', is_pdf: 0, pdf_url: '' }) }));
    expect(await screen.findByText('The schedule has not been posted yet.')).toBeTruthy();
  });
});

describe('EventDirectionsPage', () => {
  it('shows each location with its address, notes and a directions link', async () => {
    await renderEventRoute(`${BASE}/directions`);
    expect(await screen.findByRole('heading', { level: 2, name: 'Williamsburg Inn' })).toBeTruthy();
    expect(screen.getByText('Park in')).toBeTruthy();
    expect(screen.getByText('lot B')).toBeTruthy();
    expect(screen.getByRole('link', { name: /get directions to williamsburg inn/i })).toBeTruthy();
  });

  it('shows an empty message when no locations exist', async () => {
    await renderEventRoute(`${BASE}/directions`, eventHandlers({ [LOCATIONS]: () => jsonResponse(200, []) }));
    expect(await screen.findByText('No locations have been added yet.')).toBeTruthy();
  });

  it('shows an error with retry when locations fail', async () => {
    let calls = 0;
    const handler: RouteHandler = () => {
      calls += 1;
      return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, LOCATIONS_RAW);
    };
    await renderEventRoute(`${BASE}/directions`, eventHandlers({ [LOCATIONS]: handler }));
    const alert = await screen.findByRole('alert');
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 2, name: 'Williamsburg Inn' })).toBeTruthy();
  });
});

describe('EventSponsorsPage', () => {
  it('groups sponsors by tier and keeps unknown tiers in an other group', async () => {
    await renderEventRoute(`${BASE}/sponsors`);
    expect(await screen.findByRole('heading', { level: 2, name: 'M Class' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2, name: 'Gold Class' })).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2, name: 'Other sponsors' })).toBeTruthy();
    const links = screen.getAllByRole('link', { name: /odometer gears/i });
    expect(links.every((link) => link.getAttribute('href') === 'https://odometer.example/')).toBe(true);
    expect(screen.queryByRole('link', { name: /gold garage/i })).toBeNull();
    expect(screen.getByText('Gold level')).toBeTruthy();
  });

  it('shows an empty message with no sponsors', async () => {
    await renderEventRoute(`${BASE}/sponsors`, eventHandlers({ [SPONSORS]: () => jsonResponse(200, { sponsors: [], show_id: 36 }) }));
    expect(await screen.findByText('Sponsors for this event will be announced soon.')).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    const { container } = await renderEventRoute(`${BASE}/sponsors`);
    await screen.findByRole('heading', { level: 2, name: 'M Class' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('EventNewsPage', () => {
  it('shows the empty news state without fetching anything', async () => {
    const { fetchMock } = await renderEventRoute(`${BASE}/news`);
    expect(await screen.findByText('There is no news for this event yet.')).toBeTruthy();
    expect(vi.mocked(fetchMock).mock.calls.some(([url]) => String(url).includes('/news'))).toBe(false);
  });
});
