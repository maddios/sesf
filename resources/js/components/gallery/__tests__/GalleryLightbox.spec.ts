import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { createMemoryHistory, createRouter } from 'vue-router';
import GalleryLightbox from '../GalleryLightbox.vue';
import type { GalleryItem } from '../../../api/gallery';

const ITEMS: GalleryItem[] = [
  {
    id: 1,
    title: 'Front',
    sortOrder: 1,
    dateTaken: '2026-04-30',
    imageUrl: '/images/gallery/1.jpg',
    user: { fullName: 'Jane Doe', profilePath: '/Jane/u7' },
  },
  {
    id: 2,
    title: 'Side',
    sortOrder: 2,
    dateTaken: '2026-04-30',
    imageUrl: '/images/gallery/2.jpg',
    user: { fullName: 'Sam Lee', profilePath: '' },
  },
  {
    id: 3,
    title: 'Rear',
    sortOrder: 3,
    dateTaken: '',
    imageUrl: '/images/gallery/3.jpg',
    user: null,
  },
];

async function renderLightbox(startIndex = 1) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] });
  await router.push('/');
  const onUpdate = vi.fn();
  const utils = render(GalleryLightbox, {
    props: { open: true, items: ITEMS, startIndex, 'onUpdate:open': onUpdate },
    global: { plugins: [router] },
  });
  return { ...utils, onUpdate };
}

function currentImage(): HTMLImageElement {
  const image = document.querySelector<HTMLImageElement>('img');
  if (image === null) throw new Error('No photo rendered');
  return image;
}

function finishLoading(): Promise<void> {
  return fireEvent.load(currentImage());
}

function nextButton(): HTMLButtonElement {
  return screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement;
}

describe('GalleryLightbox', () => {
  it('opens at the start index with the photo count in its title', async () => {
    await renderLightbox(1);
    expect(await screen.findByRole('dialog', { name: 'Photo 2 of 3' })).toBeTruthy();
    expect(currentImage().getAttribute('alt')).toBe('Side');
  });

  it('keeps Next and Previous disabled until the photo has loaded', async () => {
    await renderLightbox(1);
    expect(nextButton().disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Previous' }) as HTMLButtonElement).disabled).toBe(true);

    await fireEvent.click(nextButton());
    expect(screen.getByRole('dialog', { name: 'Photo 2 of 3' })).toBeTruthy();

    await finishLoading();
    expect(nextButton().disabled).toBe(false);
    await fireEvent.click(nextButton());
    expect(screen.getByRole('dialog', { name: 'Photo 3 of 3' })).toBeTruthy();
  });

  it('moves forward with ArrowRight, ArrowDown and Space and back with ArrowLeft and ArrowUp', async () => {
    await renderLightbox(0);
    const dialog = () => screen.getByRole('dialog');
    await finishLoading();

    await fireEvent.keyDown(window, { key: 'ArrowRight' });
    expect(dialog().textContent).toContain('Photo 2 of 3');
    await finishLoading();

    await fireEvent.keyDown(window, { key: 'ArrowDown' });
    expect(dialog().textContent).toContain('Photo 3 of 3');
    await finishLoading();

    await fireEvent.keyDown(window, { key: 'ArrowLeft' });
    expect(dialog().textContent).toContain('Photo 2 of 3');
    await finishLoading();

    await fireEvent.keyDown(window, { key: 'ArrowUp' });
    expect(dialog().textContent).toContain('Photo 1 of 3');
    await finishLoading();

    await fireEvent.keyDown(window, { key: ' ' });
    expect(dialog().textContent).toContain('Photo 2 of 3');
  });

  it('closes on Escape', async () => {
    const { onUpdate } = await renderLightbox(1);
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onUpdate).toHaveBeenCalledWith(false);
  });

  it('shows the date, a profile link for the uploader and the original image in a new tab', async () => {
    await renderLightbox(0);
    expect(screen.getByText('Date Taken: 2026-04-30')).toBeTruthy();

    const uploader = screen.getByRole('link', { name: 'Jane Doe' });
    expect(uploader.getAttribute('href')).toBe('/Jane/u7');
    expect(uploader.closest('span')?.textContent).toContain('Submitted by:');

    const original = screen.getByRole('link', { name: 'Original Image' });
    expect(original.getAttribute('target')).toBe('_blank');
    expect(original.getAttribute('href')).toBe('/images/gallery/1.jpg');
  });

  it('shows an uploader without a profile as plain text', async () => {
    await renderLightbox(1);
    expect(screen.queryByRole('link', { name: 'Sam Lee' })).toBeNull();
    expect(screen.getByText(/Submitted by:\s+Sam Lee/)).toBeTruthy();
  });

  it('has no accessibility violations once the photo has loaded', async () => {
    await renderLightbox(0);
    await finishLoading();
    expect(await axe(document.body)).toHaveNoViolations();
  });

  it('moves with a horizontal swipe and ignores short drags', async () => {
    await renderLightbox(1);
    await finishLoading();
    const stage = screen.getByTestId('lightbox-stage');
    await fireEvent.pointerDown(stage, { clientX: 200 });
    await fireEvent.pointerUp(stage, { clientX: 190 });
    expect(screen.getByRole('dialog', { name: 'Photo 2 of 3' })).toBeTruthy();
    await fireEvent.pointerDown(stage, { clientX: 200 });
    await fireEvent.pointerUp(stage, { clientX: 100 });
    expect(screen.getByRole('dialog', { name: 'Photo 3 of 3' })).toBeTruthy();
    await finishLoading();
    await fireEvent.pointerDown(stage, { clientX: 100 });
    await fireEvent.pointerUp(stage, { clientX: 220 });
    expect(screen.getByRole('dialog', { name: 'Photo 2 of 3' })).toBeTruthy();
  });

  it('toggles zoom and resets it on the next photo', async () => {
    await renderLightbox(0);
    await finishLoading();
    const zoom = screen.getByRole('button', { name: 'Zoom' });
    await fireEvent.click(zoom);
    expect(zoom.getAttribute('aria-pressed')).toBe('true');
    await fireEvent.click(nextButton());
    expect(screen.getByRole('button', { name: 'Zoom' }).getAttribute('aria-pressed')).toBe('false');
  });
});
