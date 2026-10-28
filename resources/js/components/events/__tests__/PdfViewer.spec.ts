import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import PdfViewer from '../PdfViewer.vue';
import { loadPdfjs } from '../pdfjsLoader';

vi.mock('../pdfjsLoader', () => ({ loadPdfjs: vi.fn() }));

const destroy = vi.fn(async () => undefined);
const renderPage = vi.fn(() => ({ promise: Promise.resolve() }));
const getViewport = vi.fn(({ scale }: { scale: number }) => ({ width: 600 * scale, height: 800 * scale }));

function stubLoader(numPages: number): void {
  vi.mocked(loadPdfjs).mockResolvedValue({
    open: async () => ({ numPages, destroy, getPage: async () => ({ getViewport, render: renderPage }) }),
  });
}

beforeEach(() => {
  vi.mocked(loadPdfjs).mockReset();
  destroy.mockClear();
  renderPage.mockClear();
  getViewport.mockClear();
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(300);
  vi.stubGlobal('devicePixelRatio', 3);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('PdfViewer', () => {
  it('renders one labelled canvas per page, scaled to the container width and capped at 2x', async () => {
    stubLoader(3);
    render(PdfViewer, { props: { url: '/s.pdf', title: 'Event schedule' } });
    expect(screen.getByRole('status').textContent).toContain('Loading schedule');
    expect(await screen.findByRole('img', { name: 'Event schedule, page 3 of 3' })).toBeTruthy();
    expect(screen.getAllByRole('img')).toHaveLength(3);
    const canvas = screen.getAllByRole('img')[0] as HTMLCanvasElement;
    expect(canvas.width).toBe(600);
    expect(canvas.style.width).toBe('300px');
    expect(renderPage).toHaveBeenCalledTimes(3);
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('shows an error message when pdf.js cannot be loaded or the file cannot be opened', async () => {
    vi.mocked(loadPdfjs).mockRejectedValue(new Error('chunk failed'));
    render(PdfViewer, { props: { url: '/s.pdf', title: 'Event schedule' } });
    expect((await screen.findByRole('alert')).textContent).toContain('download link');
  });

  it('shows an error message when a page fails to render', async () => {
    stubLoader(1);
    renderPage.mockReturnValueOnce({ promise: Promise.reject(new Error('bad page')) });
    render(PdfViewer, { props: { url: '/s.pdf', title: 'Event schedule' } });
    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('releases the document on unmount', async () => {
    stubLoader(1);
    const { unmount } = render(PdfViewer, { props: { url: '/s.pdf', title: 'Event schedule' } });
    await screen.findByRole('img');
    unmount();
    await waitFor(() => expect(destroy).toHaveBeenCalledTimes(1));
  });

  it('has no accessibility violations', async () => {
    stubLoader(2);
    const { container } = render(PdfViewer, { props: { url: '/s.pdf', title: 'Event schedule' } });
    await screen.findAllByRole('img');
    expect(await axe(container)).toHaveNoViolations();
  });
});
