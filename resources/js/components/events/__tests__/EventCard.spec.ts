import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import EventCard from '../EventCard.vue';
import { toCarShow, type CarShow } from '../../../api/events';
import { CAR_SHOW_RAW, renderWithEventRouter } from '../../../pages/events/__tests__/eventFixtures';

function showWith(overrides: Record<string, unknown> = {}): CarShow {
  const show = toCarShow({ ...CAR_SHOW_RAW, ...overrides });
  if (show === null) {
    throw new Error('bad fixture');
  }
  return show;
}

describe('EventCard', () => {
  it('shows name, ended tag, description, dates, location and both links', async () => {
    await renderWithEventRouter(EventCard, { show: showWith() });
    expect(screen.getByRole('heading', { level: 3, name: /southeast sharkfest 2026/i })).toBeTruthy();
    expect(screen.getByText('(Ended)')).toBeTruthy();
    expect(screen.getByText('Apr 30, 2026 - May 3, 2026')).toBeTruthy();
    expect(screen.getByText('Richmond, VA')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Event Info for Southeast Sharkfest 2026' }).getAttribute('href')).toBe(
      '/SoutheastSharkfest2026/e36',
    );
    expect(screen.getByRole('link', { name: 'Gallery for Southeast Sharkfest 2026' }).getAttribute('href')).toBe('/gallery/e36');
  });

  it('omits the ended tag, location and description when they are missing', async () => {
    const show = showWith({
      ended: false,
      primaryLocation: null,
      regOpen: true,
      shortDescription: '',
      short_description: '',
      description: '',
    });
    const { container } = await renderWithEventRouter(EventCard, { show });
    expect(screen.queryByText('(Ended)')).toBeNull();
    expect(screen.queryByText('Location')).toBeNull();
    expect(screen.getByText('Open')).toBeTruthy();
    expect(container.querySelector('p.mt-3')).toBeNull();
  });

  it('has no accessibility violations', async () => {
    const { container } = await renderWithEventRouter(EventCard, { show: showWith() });
    expect(await axe(container)).toHaveNoViolations();
  });
});
