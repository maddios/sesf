import { describe, it, expect } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { ADMIN, MEMBER_SESSION, galleryRaw, itemRaw, jsonResponse, ok, pageRaw, renderGalleryAt } from './galleryFixtures';
import { INFO_RAW } from '../../events/__tests__/eventFixtures';

const EVENT_INFO = 'GET /event/info?show_id=36';
const CREATE = 'POST /gallery/create';
const MANAGE_12 = {
  'GET /gallery/info?gallery_id=12': ok(galleryRaw({ id: 12, title: 'Summer' })),
  'GET /gallery/items?gallery_id=12': ok(pageRaw([itemRaw(1, 'Front')])),
};

describe('GalleryCreatePage', () => {
  it('creates a named gallery for an admin and opens its manage page', async () => {
    const { fetchMock, router } = await renderGalleryAt(
      '/gallery/create/36',
      {
        [EVENT_INFO]: ok(INFO_RAW),
        [CREATE]: ok(galleryRaw({ id: 12, title: 'Summer' })),
        ...MANAGE_12,
      },
      ADMIN,
    );
    await fireEvent.update(await screen.findByLabelText('Title'), 'Summer');
    await fireEvent.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/gallery/manage/12'));
    const posted = fetchMock.mock.calls.find(([url, init]) => url === '/gallery/create' && init?.method === 'POST');
    expect(JSON.parse(String(posted?.[1]?.body))).toEqual({ show_id: 36, title: 'Summer' });
  });

  it('creates a sub-gallery when the admin came from a gallery', async () => {
    const { fetchMock, router } = await renderGalleryAt(
      '/gallery/create/36/5',
      {
        [EVENT_INFO]: ok(INFO_RAW),
        'GET /gallery/info?gallery_id=5': ok(galleryRaw({ id: 5, title: 'Cars' })),
        [CREATE]: ok(galleryRaw({ id: 12, title: 'Trucks', parent_id: 5 })),
        ...MANAGE_12,
      },
      ADMIN,
    );
    await fireEvent.update(await screen.findByLabelText('Title'), 'Trucks');
    await fireEvent.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/gallery/manage/12'));
    const posted = fetchMock.mock.calls.find(([url, init]) => url === '/gallery/create' && init?.method === 'POST');
    expect(JSON.parse(String(posted?.[1]?.body))).toEqual({ show_id: 36, title: 'Trucks', gallery_id: 5 });
  });

  it('creates the album for a member on arrival and opens its manage page', async () => {
    const { fetchMock, router } = await renderGalleryAt(
      '/gallery/create/36',
      {
        [EVENT_INFO]: ok(INFO_RAW),
        [CREATE]: ok(galleryRaw({ id: 12, title: 'Shark F.' })),
        ...MANAGE_12,
      },
      MEMBER_SESSION,
    );
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/gallery/manage/12'));
    const posted = fetchMock.mock.calls.find(([url, init]) => url === '/gallery/create' && init?.method === 'POST');
    expect(JSON.parse(String(posted?.[1]?.body))).toEqual({ show_id: 36 });
  });

  it('shows the server message when a member is not checked in', async () => {
    const { router } = await renderGalleryAt(
      '/gallery/create/36',
      {
        [EVENT_INFO]: ok(INFO_RAW),
        [CREATE]: () => jsonResponse(403, { error: 'Must be checked in for this event.' }),
      },
      MEMBER_SESSION,
    );
    expect(await screen.findByText('Must be checked in for this event.')).toBeTruthy();
    expect(router.currentRoute.value.fullPath).toBe('/gallery/create/36');
  });

  it('has no accessibility violations for the admin form', async () => {
    const { container } = await renderGalleryAt('/gallery/create/36', { [EVENT_INFO]: ok(INFO_RAW) }, ADMIN);
    await screen.findByLabelText('Title');
    expect(await axe(container)).toHaveNoViolations();
  });
});
