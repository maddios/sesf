import { describe, it, expect } from 'vitest';
import { screen, within, waitFor, fireEvent } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { jsonResponse, type RouteHandler } from '../../../test/helpers';
import { INFO_RAW, eventHandlers, renderEventRoute } from './eventFixtures';

const BASE = '/SoutheastSharkfest2026/e36';
const INFO = 'GET /event/info?show_id=36';

function tabLabels(): string[] {
  const nav = screen.getByRole('navigation', { name: 'Event sections' });
  return within(nav)
    .getAllByRole('link')
    .map((link) => link.textContent?.trim() ?? '');
}

async function renderWithInfo(info: Record<string, unknown>) {
  return renderEventRoute(BASE, eventHandlers({ [INFO]: () => jsonResponse(200, { ...INFO_RAW, ...info }) }));
}

describe('EventLayout tabs', () => {
  it('shows every tab for an event with judging, a schedule and open registration', async () => {
    await renderEventRoute(BASE);
    await screen.findByRole('navigation', { name: 'Event sections' });
    expect(tabLabels()).toEqual(['Event Info', 'Judging', 'Schedule', 'Directions', "Who's Coming", 'Register', 'Sponsors']);
  });

  it('hides Judging, Schedule and Register when they do not apply', async () => {
    await renderWithInfo({ agendaTypes: { agenda: 2, judging: 0 }, hasSchedule: false, regOpen: false });
    await screen.findByRole('navigation', { name: 'Event sections' });
    expect(tabLabels()).toEqual(['Event Info', 'Directions', "Who's Coming", 'Sponsors']);
  });

  it('links Register to the registration path only while registration is open', async () => {
    await renderWithInfo({ regOpen: true });
    const register = await screen.findByRole('link', { name: 'Register' });
    expect(register.getAttribute('href')).toBe('/SoutheastSharkfest2026/e36/register');
  });

  it('marks the active tab and sets the page title to the event name', async () => {
    await renderEventRoute(`${BASE}/directions`);
    const nav = await screen.findByRole('navigation', { name: 'Event sections' });
    expect(within(nav).getByRole('link', { name: 'Directions' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('heading', { level: 1, name: 'Southeast Sharkfest 2026' })).toBeTruthy();
    await waitFor(() => expect(document.title).toBe('Southeast Sharkfest 2026 | Southeast Sharkfest'));
  });
});

describe('EventLayout states', () => {
  it('shows a friendly not-found page for an unknown event', async () => {
    await renderEventRoute(BASE, eventHandlers({ [INFO]: () => jsonResponse(404, { error: 'missing' }) }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Event not found' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'all events' }).getAttribute('href')).toBe('/events');
    await waitFor(() => expect(document.title).toBe('Event not found | Southeast Sharkfest'));
  });

  it('shows an error with a retry that reloads the event', async () => {
    let calls = 0;
    const flaky: RouteHandler = () => {
      calls += 1;
      return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, INFO_RAW);
    };
    await renderEventRoute(BASE, eventHandlers({ [INFO]: flaky }));
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('This event could not be loaded right now.')).toBeTruthy();
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Southeast Sharkfest 2026' })).toBeTruthy();
  });

  it('has no accessibility violations once loaded', async () => {
    const { container } = await renderEventRoute(BASE);
    await screen.findByRole('navigation', { name: 'Event sections' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
