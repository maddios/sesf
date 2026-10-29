import { describe, it, expect } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import HomePage from '../HomePage.vue';
import { callsTo, jsonResponse, renderAt, stubFetch, type RouteHandler } from '../../test/helpers';
import { LEGACY_EVENT } from '../../test/fixtures';

const EVENTS = 'GET /home/events?limit=1';
const SPONSORS = 'GET /event/sponsors?limit=6&all=true';

const SPONSORS_BODY = {
  show_id: 36,
  sponsors: [
    { id: 45, name: 'BMWCCA E31 Chapter', URL: 'https://www.bmwccae31.com/', imageURL: 'images/sponsor/45.jpg' },
    { id: 48, name: 'Blueridge Chapter - BMWCCA', URL: '', imageURL: 'images/sponsor/48.jpg' },
  ],
};

async function renderHome(handlers: Record<string, RouteHandler>) {
  const fetchMock = stubFetch(handlers);
  const utils = await renderAt(HomePage, '/');
  return { fetchMock, ...utils };
}

describe('HomePage', () => {
  it('has a page heading and the privacy-enhanced overview video', async () => {
    const { container } = await renderHome({ [EVENTS]: () => jsonResponse(200, []), [SPONSORS]: () => jsonResponse(200, { sponsors: [] }) });
    expect(screen.getByRole('heading', { level: 1, name: /welcome to southeast sharkfest/i })).toBeTruthy();
    const video = container.querySelector('iframe[title="Southeast Sharkfest 2025 overview video"]');
    expect(video?.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/zrtLOBS4R9A?rel=0');
    expect(screen.getByRole('heading', { level: 2, name: 'SESF Past Videos' })).toBeTruthy();
  });

  it('shows loading states, then the latest event with its (Ended) tag, links and sponsors', async () => {
    const { fetchMock } = await renderHome({
      [EVENTS]: () => jsonResponse(200, [LEGACY_EVENT]),
      [SPONSORS]: () => jsonResponse(200, SPONSORS_BODY),
    });
    expect(screen.getByText('Loading events')).toBeTruthy();
    expect(screen.getByText('Loading sponsors')).toBeTruthy();

    const title = await screen.findByRole('heading', { level: 3, name: /Southeast Sharkfest 2026/ });
    expect(title.textContent).toContain('(Ended)');
    const summary = document.querySelector('.event-summary');
    expect(summary?.textContent).toMatch(/We are pleased to announce.the date\.\.\./);
    expect(summary?.querySelector('strong')?.textContent).toBe('the date');
    expect(screen.getByRole('link', { name: 'More info about Southeast Sharkfest 2026' }).getAttribute('href')).toBe('/SoutheastSharkfest2026/e36');
    expect(screen.getByRole('link', { name: 'Show all events' }).getAttribute('href')).toBe('/events');

    const primary = screen.getByRole('link', { name: /Odometer Gears/ });
    expect(primary.getAttribute('href')).toBe('http://www.odometergears.com/');
    expect(primary.getAttribute('target')).toBe('_blank');
    expect(primary.getAttribute('rel')).toBe('noopener noreferrer');

    const e31 = await screen.findByRole('link', { name: /BMWCCA E31 Chapter/ });
    expect(e31.querySelector('img')?.getAttribute('src')).toBe('/images/4/250/65/images/sponsor/45.jpg');
    // No URL: the logo is shown without a link.
    expect(screen.getByRole('img', { name: 'Blueridge Chapter - BMWCCA' }).closest('a')).toBeNull();
    expect(screen.getByRole('link', { name: 'See all sponsors' }).getAttribute('href')).toBe('/show/e36/sponsors');
    expect(callsTo(fetchMock, EVENTS)).toHaveLength(1);
  });

  it('a broken sponsor logo becomes a placeholder tile with the sponsor name', async () => {
    const { container } = await renderHome({ [EVENTS]: () => jsonResponse(200, []), [SPONSORS]: () => jsonResponse(200, SPONSORS_BODY) });
    const img = (await screen.findByRole('img', { name: 'BMWCCA E31 Chapter' })) as HTMLImageElement;
    await fireEvent.error(img);
    const tile = screen.getByRole('img', { name: 'BMWCCA E31 Chapter' });
    expect(tile.tagName).toBe('SPAN');
    expect(tile.textContent).toContain('BMWCCA E31 Chapter');
    expect(container.querySelector('img[alt="BMWCCA E31 Chapter"]')).toBeNull();
  });

  it('shows empty states when there are no events or sponsors', async () => {
    await renderHome({ [EVENTS]: () => jsonResponse(200, []), [SPONSORS]: () => jsonResponse(200, { sponsors: [], show_id: null }) });
    expect(await screen.findByText('No events have been announced yet. Check back soon.')).toBeTruthy();
    expect(await screen.findByText('Sponsors will be announced soon.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'See all sponsors' }).getAttribute('href')).toBe('/events');
  });

  it('shows error states with a retry when the endpoints fail (e.g. 404 before the backend exists)', async () => {
    let eventsOk = false;
    const { fetchMock } = await renderHome({
      [EVENTS]: () => (eventsOk ? jsonResponse(200, [LEGACY_EVENT]) : jsonResponse(404, { message: 'Not Found' })),
      [SPONSORS]: () => jsonResponse(500, 'boom'),
    });
    expect(await screen.findByText('The latest event could not be loaded right now.')).toBeTruthy();
    expect(await screen.findByText('Sponsors could not be loaded right now.')).toBeTruthy();

    eventsOk = true;
    const user = userEvent.setup();
    await user.click(screen.getAllByRole('button', { name: 'Try again' })[0] as HTMLElement);
    expect(await screen.findByRole('heading', { level: 3, name: /Southeast Sharkfest 2026/ })).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(callsTo(fetchMock, SPONSORS)).toHaveLength(2));
  });

  it('keeps the legacy PayPal donate form', async () => {
    const { container } = await renderHome({ [EVENTS]: () => jsonResponse(200, []), [SPONSORS]: () => jsonResponse(200, { sponsors: [] }) });
    const form = container.querySelector('form') as HTMLFormElement;
    expect(form.getAttribute('action')).toBe('https://www.paypal.com/donate');
    expect(form.getAttribute('method')).toBe('post');
    expect(form.getAttribute('target')).toBe('_blank');
    expect(form.getAttribute('rel')).toBe('noopener noreferrer');
    expect((form.querySelector('input[name="hosted_button_id"]') as HTMLInputElement).value).toBe('9LUSEKNJDPB4A');
    expect(screen.getByRole('button', { name: /Donate\s*with PayPal\s*\(opens in a new tab\)/ })).toBeTruthy();
  });

  it('has no detectable accessibility violations once loaded', async () => {
    const { container } = await renderHome({ [EVENTS]: () => jsonResponse(200, [LEGACY_EVENT]), [SPONSORS]: () => jsonResponse(200, SPONSORS_BODY) });
    await screen.findByRole('heading', { level: 3, name: /Southeast Sharkfest 2026/ });
    await screen.findByRole('link', { name: /BMWCCA E31 Chapter/ });
    // axe cannot message into the YouTube iframes under jsdom.
    expect(await axe(container, { iframes: false })).toHaveNoViolations();
  });
});
