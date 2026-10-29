import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import GalleryUploader from '../GalleryUploader.vue';
import { uploadGalleryPhoto } from '../../../api/gallery';
import { ApiError } from '../../../api/client';

vi.mock('../../../api/gallery', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../../api/gallery')>()),
  uploadGalleryPhoto: vi.fn(),
}));

const upload = vi.mocked(uploadGalleryPhoto);

function photo(name: string): File {
  return new File(['pixels'], name, { type: 'image/jpeg' });
}

function deferred() {
  let resolve!: () => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<void>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

function renderUploader() {
  return render(GalleryUploader, { props: { galleryId: 5 } });
}

function chooseFiles(container: Element, files: File[]) {
  const input = container.querySelector<HTMLInputElement>('input[type="file"]');
  if (input === null) throw new Error('No file input rendered');
  return fireEvent.change(input, { target: { files } });
}

beforeEach(() => {
  upload.mockReset();
});

describe('GalleryUploader', () => {
  it('uploads chosen photos one at a time, removes finished ones and emits uploaded once', async () => {
    const first = deferred();
    const second = deferred();
    upload
      .mockImplementationOnce((_galleryId, _file, onProgress) => {
        onProgress(0.5);
        return first.promise;
      })
      .mockReturnValueOnce(second.promise);
    const { container, emitted } = renderUploader();

    await chooseFiles(container, [photo('a.jpg'), photo('b.jpg')]);
    await waitFor(() => expect(upload).toHaveBeenCalledTimes(1));
    const bar = screen.getByRole('progressbar', { name: 'Uploading a.jpg' }) as HTMLProgressElement;
    expect(bar.value).toBe(50);

    first.resolve();
    await waitFor(() => expect(upload).toHaveBeenCalledTimes(2));
    expect(upload).toHaveBeenNthCalledWith(2, 5, expect.objectContaining({ name: 'b.jpg' }), expect.any(Function), expect.any(AbortSignal));
    await waitFor(() => expect(screen.queryByText('a.jpg')).toBeNull());
    expect(emitted().uploaded).toBeUndefined();

    second.resolve();
    await waitFor(() => expect(emitted().uploaded).toHaveLength(1));
    expect(screen.queryByRole('list', { name: 'Upload queue' })).toBeNull();
  });

  it('shows the not-allowed message when the server refuses with 403', async () => {
    upload.mockRejectedValueOnce(new ApiError(403, { error: 'Forbidden' }));
    const { container, emitted } = renderUploader();

    await chooseFiles(container, [photo('a.jpg')]);
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText('You are not allowed to upload to this gallery.')).toBeTruthy();
    expect(emitted().uploaded).toBeUndefined();
  });

  it('refuses a non-image file without uploading it', async () => {
    const { container } = renderUploader();

    await chooseFiles(container, [new File(['text'], 'notes.txt', { type: 'text/plain' })]);
    expect(screen.getByText('Only image files can be uploaded.')).toBeTruthy();
    expect(upload).not.toHaveBeenCalled();
  });

  it('queues files dropped on the dropzone', async () => {
    upload.mockResolvedValue(undefined);
    renderUploader();

    await fireEvent.drop(screen.getByTestId('gallery-dropzone'), { dataTransfer: { files: [photo('dropped.jpg')] } });
    await waitFor(() => expect(upload).toHaveBeenCalledWith(5, expect.objectContaining({ name: 'dropped.jpg' }), expect.any(Function), expect.any(AbortSignal)));
  });

  it('clears the queue and stops uploading on Cancel', async () => {
    upload.mockImplementation(
      (_galleryId, _file, _onProgress, signal) =>
        new Promise<void>((_resolve, reject) => {
          signal?.addEventListener('abort', () => reject(new ApiError(0, null, 'Upload cancelled')));
        }),
    );
    const { container, emitted } = renderUploader();

    await chooseFiles(container, [photo('a.jpg'), photo('b.jpg')]);
    await waitFor(() => expect(upload).toHaveBeenCalledTimes(1));
    await fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByRole('list', { name: 'Upload queue' })).toBeNull();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(upload).toHaveBeenCalledTimes(1);
    expect(emitted().uploaded).toBeUndefined();
  });

  it('has no accessibility violations with a photo in the queue', async () => {
    upload.mockReturnValue(new Promise<void>(() => undefined));
    const { container } = renderUploader();

    await chooseFiles(container, [photo('a.jpg')]);
    await screen.findByRole('progressbar', { name: 'Uploading a.jpg' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
