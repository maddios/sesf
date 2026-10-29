import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import VideoCarousel from '../VideoCarousel.vue';

const VIDEOS = [
  { id: 'aaa', title: 'Video A' },
  { id: 'bbb', title: 'Video B' },
  { id: 'ccc', title: 'Video C' },
];

function iframes(container: Element) {
  return Array.from(container.querySelectorAll('iframe'));
}

describe('VideoCarousel', () => {
  it('renders only the visible slide as a privacy-enhanced, titled iframe', () => {
    const { container } = render(VideoCarousel, { props: { videos: VIDEOS, label: 'Past videos' } });
    expect(screen.getByRole('region', { name: 'Past videos' }).getAttribute('aria-roledescription')).toBe('carousel');
    const frames = iframes(container);
    expect(frames).toHaveLength(1);
    expect(frames[0]?.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/aaa?rel=0');
    expect(frames[0]?.getAttribute('title')).toBe('Video A');
    expect(frames[0]?.getAttribute('loading')).toBe('lazy');
    expect(screen.getByRole('group', { name: '1 of 3' }).getAttribute('aria-live')).toBe('off');
  });

  it('next, previous and dots change the slide (wrapping) and announce it', async () => {
    const user = userEvent.setup();
    const { container } = render(VideoCarousel, { props: { videos: VIDEOS, label: 'Past videos' } });
    await user.click(screen.getByRole('button', { name: 'Next video' }));
    expect(iframes(container)[0]?.getAttribute('title')).toBe('Video B');
    expect(screen.getByRole('group', { name: '2 of 3' }).getAttribute('aria-live')).toBe('polite');

    await user.click(screen.getByRole('button', { name: 'Previous video' }));
    await user.click(screen.getByRole('button', { name: 'Previous video' }));
    expect(iframes(container)[0]?.getAttribute('title')).toBe('Video C');

    await user.click(screen.getByRole('button', { name: 'Show video 2: Video B' }));
    expect(iframes(container)[0]?.getAttribute('title')).toBe('Video B');
    expect(screen.getByRole('button', { name: 'Show video 2: Video B' }).getAttribute('aria-current')).toBe('true');
    expect(screen.getByRole('button', { name: 'Show video 1: Video A' }).getAttribute('aria-current')).toBeNull();
  });

  it('hides the controls for a single video and renders nothing for none', () => {
    const { container, unmount } = render(VideoCarousel, { props: { videos: [VIDEOS[0]], label: 'One' } });
    expect(screen.queryByRole('button', { name: 'Next video' })).toBeNull();
    expect(iframes(container)).toHaveLength(1);
    unmount();
    const empty = render(VideoCarousel, { props: { videos: [], label: 'None' } });
    expect(iframes(empty.container)).toHaveLength(0);
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(VideoCarousel, { props: { videos: VIDEOS, label: 'Past videos' } });
    // axe cannot message into the YouTube iframes under jsdom.
    expect(await axe(container, { iframes: false })).toHaveNoViolations();
  });
});
