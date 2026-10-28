import { afterEach, describe, it, expect, vi } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { ADMIN, MEMBER_SESSION, galleryRaw, itemRaw, jsonResponse, ok, pageRaw, renderGalleryAt } from './galleryFixtures';

const ITEMS = [itemRaw(1, 'Front'), itemRaw(2, 'Rear'), itemRaw(3, '')];
const INFO = 'GET /gallery/info?gallery_id=12';
const ITEMS_KEY = 'GET /gallery/items?gallery_id=12';

function ownedBy(userId: number, overrides: Record<string, unknown> = {}) {
  return { [INFO]: ok(galleryRaw({ id: 12, user_id: userId, title: 'Mine', is_enabled: 1, ...overrides })), [ITEMS_KEY]: ok(pageRaw(ITEMS)) };
}

function postedBodies(fetchMock: { mock: { calls: unknown[][] } }, path: string): unknown[] {
  return fetchMock.mock.calls
    .filter(([url, init]) => url === path && (init as RequestInit | undefined)?.method === 'POST')
    .map(([, init]) => JSON.parse(String((init as RequestInit).body)));
}

afterEach(() => vi.restoreAllMocks());

describe('GalleryManagePage', () => {
  it('shows the uploader and photo list to the owner', async () => {
    await renderGalleryAt('/gallery/manage/12', ownedBy(7), MEMBER_SESSION);
    expect(await screen.findByRole('heading', { name: 'Add photos' })).toBeTruthy();
    expect(screen.getByLabelText('Title for photo 1')).toBeTruthy();
  });

  it('saves the moved order with sort_order and titles', async () => {
    const { fetchMock } = await renderGalleryAt(
      '/gallery/manage/12',
      {
        ...ownedBy(7),
        'POST /gallery/items': ok(pageRaw(ITEMS)),
      },
      MEMBER_SESSION,
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'Move Front down' }));
    await fireEvent.update(screen.getByLabelText('Title for photo 3'), 'Side');
    await fireEvent.click(screen.getByRole('button', { name: 'Save order' }));

    expect(await screen.findByText('Saved')).toBeTruthy();
    expect(postedBodies(fetchMock, '/gallery/items')).toEqual([
      {
        gallery_id: 12,
        items: [
          { id: 2, title: 'Rear', sort_order: 1 },
          { id: 1, title: 'Front', sort_order: 2 },
          { id: 3, title: 'Side', sort_order: 3 },
        ],
      },
    ]);
  });

  it('sets the cover after confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { fetchMock } = await renderGalleryAt(
      '/gallery/manage/12',
      { ...ownedBy(7), 'POST /gallery/default-item': ok({}) },
      MEMBER_SESSION,
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'Cover for photo 2' }));
    await waitFor(() => expect(postedBodies(fetchMock, '/gallery/default-item')).toEqual([{ gallery_id: 12, item_id: 2 }]));
  });

  it('deletes a photo after confirmation and removes its row', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { fetchMock } = await renderGalleryAt(
      '/gallery/manage/12',
      { ...ownedBy(7), 'POST /gallery/remove-item': ok({}) },
      MEMBER_SESSION,
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'Delete photo 2' }));
    await waitFor(() => expect(screen.queryByDisplayValue('Rear')).toBeNull());
    expect(postedBodies(fetchMock, '/gallery/remove-item')).toEqual([{ gallery_id: 12, item_id: 2 }]);
  });

  it('keeps a photo when the delete is not confirmed', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const { fetchMock } = await renderGalleryAt('/gallery/manage/12', ownedBy(7), MEMBER_SESSION);
    await fireEvent.click(await screen.findByRole('button', { name: 'Delete photo 2' }));
    expect(screen.getByDisplayValue('Rear')).toBeTruthy();
    expect(postedBodies(fetchMock, '/gallery/remove-item')).toEqual([]);
  });

  it('lets an admin disable an enabled gallery', async () => {
    const { fetchMock } = await renderGalleryAt(
      '/gallery/manage/12',
      { ...ownedBy(99), 'POST /gallery/show-hide': ok({}) },
      ADMIN,
    );
    await fireEvent.click(await screen.findByRole('button', { name: 'Disable' }));
    expect(await screen.findByRole('button', { name: 'Enable' })).toBeTruthy();
    expect(postedBodies(fetchMock, '/gallery/show-hide')).toEqual([{ gallery_id: 12, is_enabled: 0 }]);
  });

  it('tells the owner a disabled gallery waits for review', async () => {
    await renderGalleryAt('/gallery/manage/12', ownedBy(7, { is_enabled: 0 }), MEMBER_SESSION);
    expect(await screen.findByText(/disabled until an admin reviews it/)).toBeTruthy();
  });

  it('refuses a member who does not own the gallery', async () => {
    await renderGalleryAt('/gallery/manage/12', ownedBy(99), MEMBER_SESSION);
    expect(await screen.findByText("You can't manage this gallery.")).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Add photos' })).toBeNull();
  });

  it('has no accessibility violations for the owner view', async () => {
    const { container } = await renderGalleryAt('/gallery/manage/12', ownedBy(7), MEMBER_SESSION);
    await screen.findByLabelText('Title for photo 1');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows an error when the gallery cannot be loaded', async () => {
    await renderGalleryAt('/gallery/manage/12', { [INFO]: () => jsonResponse(500, {}) }, MEMBER_SESSION);
    expect(await screen.findByText('This gallery could not be loaded right now.')).toBeTruthy();
  });
});
