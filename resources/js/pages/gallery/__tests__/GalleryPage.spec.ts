import { describe, it, expect } from 'vitest';
import { fireEvent, screen, within } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import {
  ADMIN,
  MEMBER_SESSION,
  eventLevelHandlers,
  galleryLevelHandlers,
  galleryRaw,
  itemRaw,
  ok,
  pageRaw,
  renderGalleryAt,
} from './galleryFixtures';
import { jsonResponse } from '../../../test/helpers';

const CHECKED_IN = 'GET /gallery/checked-in?show_id=36';
const ALL_ITEMS = 'GET /gallery/items?show_id=36&get_subcategory_items=1&all_items=1';
const SECOND_PAGE = 'GET /gallery/items?show_id=36&skip=2&get_subcategory_items=1';
const TWO_PHOTOS = [itemRaw(1, 'Front'), itemRaw(2, 'Back')];

function calledUrls(fetchMock: { mock: { calls: unknown[][] } }): string[] {
  return fetchMock.mock.calls.map(([url]) => String(url));
}

describe('GalleryPage', () => {
  it('shows the event breadcrumb with All as the current crumb at event level', async () => {
    await renderGalleryAt('/gallery/e36', eventLevelHandlers());
    const crumbs = within(await screen.findByRole('navigation', { name: 'Breadcrumb' }));
    expect(crumbs.getByRole('link', { name: 'Galleries' }).getAttribute('href')).toBe('/gallery');
    expect(crumbs.getByRole('link', { name: 'Southeast Sharkfest 2026' }).getAttribute('href')).toBe('/gallery/e36');
    expect(crumbs.getByText('All').getAttribute('aria-current')).toBe('page');
  });

  it('shows ancestor galleries and the current gallery title in the breadcrumb', async () => {
    await renderGalleryAt('/gallery/e36/5', galleryLevelHandlers(), null);
    const crumbs = within(await screen.findByRole('navigation', { name: 'Breadcrumb' }));
    expect(crumbs.getByRole('link', { name: 'Parent' }).getAttribute('href')).toBe('/gallery/e36/3');
    expect(crumbs.getByText('Cars').getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('heading', { level: 1, name: 'Cars' })).toBeTruthy();
  });

  it('lists sub-galleries and marks disabled ones', async () => {
    await renderGalleryAt(
      '/gallery/e36',
      eventLevelHandlers({
        'GET /gallery/list?show_id=36': ok([
          galleryRaw({ id: 6, title: 'Sub A', is_enabled: 1, parent_id: 5 }),
          galleryRaw({ id: 7, title: 'Sub B', is_enabled: 0, parent_id: 5 }),
        ]),
      }),
    );
    expect(await screen.findByText('Sub A')).toBeTruthy();
    expect(screen.getByText('Sub B (disabled)')).toBeTruthy();
    expect(screen.getByRole('link', { name: /Sub B/ }).getAttribute('href')).toBe('/gallery/e36/7');
  });

  it('shows each photo as a button labelled with its position and title', async () => {
    await renderGalleryAt('/gallery/e36', eventLevelHandlers({ 'GET /gallery/items?show_id=36&get_subcategory_items=1': ok(pageRaw([itemRaw(1, 'Front'), itemRaw(2, '')])) }));
    expect(await screen.findByRole('button', { name: 'View photo 1: Front' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'View photo 2' })).toBeTruthy();
  });

  it('loads the next page of photos with Load More Images and appends it', async () => {
    const { fetchMock } = await renderGalleryAt(
      '/gallery/e36',
      eventLevelHandlers({
        'GET /gallery/items?show_id=36&get_subcategory_items=1': ok(pageRaw(TWO_PHOTOS, 3, 2)),
        [SECOND_PAGE]: ok(pageRaw([itemRaw(3, 'Third')], 3, 2)),
      }),
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'Load More Images' }));
    expect(await screen.findByRole('button', { name: 'View photo 3: Third' })).toBeTruthy();
    expect(calledUrls(fetchMock)).toContain('/gallery/items?show_id=36&skip=2&get_subcategory_items=1');
    expect(screen.queryByRole('button', { name: 'Load More Images' })).toBeNull();
  });

  it('opens the viewer on every photo, with the full list loaded for it', async () => {
    const { fetchMock } = await renderGalleryAt(
      '/gallery/e36',
      eventLevelHandlers({
        'GET /gallery/items?show_id=36&get_subcategory_items=1': ok(pageRaw(TWO_PHOTOS)),
        [ALL_ITEMS]: ok(pageRaw(TWO_PHOTOS)),
      }),
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'View photo 2: Back' }));
    expect(await screen.findByRole('dialog', { name: 'Photo 2 of 2' })).toBeTruthy();
    expect(calledUrls(fetchMock)).toContain('/gallery/items?show_id=36&get_subcategory_items=1&all_items=1');
  });

  it('gives an admin Create Gallery at event level', async () => {
    await renderGalleryAt('/gallery/e36', eventLevelHandlers(), ADMIN);
    expect((await screen.findByRole('link', { name: 'Create Gallery' })).getAttribute('href')).toBe('/gallery/create/36');
    expect(screen.queryByRole('link', { name: 'Manage Gallery' })).toBeNull();
  });

  it('gives an admin Create Sub-Gallery and Manage Gallery inside a gallery', async () => {
    await renderGalleryAt('/gallery/e36/5', galleryLevelHandlers(), ADMIN);
    expect((await screen.findByRole('link', { name: 'Create Sub-Gallery' })).getAttribute('href')).toBe('/gallery/create/36/5');
    expect(screen.getByRole('link', { name: 'Manage Gallery' }).getAttribute('href')).toBe('/gallery/manage/5');
  });

  it('offers a checked-in member without an album Add my photos', async () => {
    await renderGalleryAt(
      '/gallery/e36',
      eventLevelHandlers({ [CHECKED_IN]: ok({ checked_in: true, member_gallery_id: null }) }),
      MEMBER_SESSION,
    );
    expect((await screen.findByRole('link', { name: 'Add my photos' })).getAttribute('href')).toBe('/gallery/create/36');
    expect(screen.queryByRole('link', { name: 'Edit my photos' })).toBeNull();
  });

  it('offers a member with an album Edit my photos', async () => {
    await renderGalleryAt(
      '/gallery/e36',
      eventLevelHandlers({ [CHECKED_IN]: ok({ checked_in: true, member_gallery_id: 9 }) }),
      MEMBER_SESSION,
    );
    expect((await screen.findByRole('link', { name: 'Edit my photos' })).getAttribute('href')).toBe('/gallery/manage/9');
    expect(screen.queryByRole('link', { name: 'Add my photos' })).toBeNull();
  });

  it('shows a not-available message when the gallery is forbidden', async () => {
    await renderGalleryAt(
      '/gallery/e36/5',
      galleryLevelHandlers({ 'GET /gallery/info?gallery_id=5': () => jsonResponse(403, { error: 'Forbidden' }) }),
      MEMBER_SESSION,
    );
    expect(await screen.findByText('This gallery is not available.')).toBeTruthy();
  });

  it('has no accessibility violations once a gallery is loaded', async () => {
    const { container } = await renderGalleryAt('/gallery/e36/5', galleryLevelHandlers(), ADMIN);
    await screen.findByRole('heading', { level: 1, name: 'Cars' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
