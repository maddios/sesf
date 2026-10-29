import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { ADMIN, MEMBER_SESSION, SHOW_RAW, ok, renderGalleryAt } from './galleryFixtures';

const SHOWS_KEY = 'GET /gallery/car-show-list';

describe('GalleryEventsPage', () => {
  it('lists an event card per show, linking to its gallery', async () => {
    await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([SHOW_RAW]) }, MEMBER_SESSION);
    const link = await screen.findByRole('link', { name: 'Southeast Sharkfest 2026' });
    expect(link.getAttribute('href')).toBe('/gallery/e36');
  });

  it('shows an admin the create link for each event', async () => {
    await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([SHOW_RAW]) }, ADMIN);
    const create = await screen.findByRole('link', { name: /^Create gallery/ });
    expect(create.getAttribute('href')).toBe('/gallery/create/36');
  });

  it('hides the create link from a member', async () => {
    await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([SHOW_RAW]) }, MEMBER_SESSION);
    await screen.findByRole('link', { name: 'Southeast Sharkfest 2026' });
    expect(screen.queryByRole('link', { name: /^Create gallery/ })).toBeNull();
  });

  it('hides the create link from a guest', async () => {
    await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([SHOW_RAW]) });
    await screen.findByRole('link', { name: 'Southeast Sharkfest 2026' });
    expect(screen.queryByRole('link', { name: /^Create gallery/ })).toBeNull();
  });

  it('shows the empty state when no event has a gallery', async () => {
    await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([]) });
    expect(await screen.findByText('No galleries yet.')).toBeTruthy();
  });

  it('has no accessibility violations once loaded', async () => {
    const { container } = await renderGalleryAt('/gallery', { [SHOWS_KEY]: ok([SHOW_RAW]) }, ADMIN);
    await screen.findByRole('link', { name: 'Southeast Sharkfest 2026' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
