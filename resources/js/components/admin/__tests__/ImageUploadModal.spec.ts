import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import ImageUploadModal from '../ImageUploadModal.vue';
import { ApiError } from '../../../api/client';

function harness(upload: (file: File, onProgress: (p: number) => void) => Promise<unknown>) {
  return defineComponent({
    setup() {
      const open = ref(false);
      const done = ref('');
      return () => [
        h('button', { type: 'button', onClick: () => (open.value = true) }, 'Upload logo'),
        h('p', { 'data-testid': 'done' }, done.value),
        h(ImageUploadModal, {
          open: open.value,
          title: 'Logo for Acme',
          currentSrc: '/images/sponsor/1.jpg',
          upload,
          'onUpdate:open': (value: boolean) => (open.value = value),
          onUploaded: (result: unknown) => (done.value = JSON.stringify(result)),
        }),
      ];
    },
  });
}

const png = () => new File(['png-bytes'], 'logo.png', { type: 'image/png' });

afterEach(() => {
  document.body.style.overflow = '';
});

describe('ImageUploadModal', () => {
  it('rejects the wrong type and oversize files before uploading', async () => {
    const user = userEvent.setup({ applyAccept: false });
    const upload = vi.fn();
    render(harness(upload));
    await user.click(screen.getByRole('button', { name: 'Upload logo' }));
    const input = await screen.findByLabelText('Choose an image');
    await user.upload(input, new File(['x'], 'a.txt', { type: 'text/plain' }));
    expect(screen.getByText('Choose a JPEG, PNG, GIF or WebP image.')).toBeTruthy();
    const big = new File(['x'], 'big.png', { type: 'image/png' });
    Object.defineProperty(big, 'size', { value: 13 * 1024 * 1024 });
    await user.upload(input, big);
    expect(screen.getByText('Choose an image of 12 MB or smaller.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Upload' }) as HTMLButtonElement).disabled).toBe(true);
    expect(upload).not.toHaveBeenCalled();
  });

  it('uploads with a progress bar, emits uploaded, closes and returns focus', async () => {
    const user = userEvent.setup();
    let finish!: (value: unknown) => void;
    let report!: (p: number) => void;
    const upload = vi.fn((_file: File, onProgress: (p: number) => void) => {
      report = onProgress;
      return new Promise((resolve) => (finish = resolve));
    });
    render(harness(upload));
    const opener = screen.getByRole('button', { name: 'Upload logo' });
    await user.click(opener);
    const dialog = await screen.findByRole('dialog', { name: 'Logo for Acme' });
    expect(await axe(dialog)).toHaveNoViolations();
    const file = png();
    await user.upload(screen.getByLabelText('Choose an image'), file);
    await user.click(screen.getByRole('button', { name: 'Upload' }));

    expect(upload).toHaveBeenCalledWith(file, expect.any(Function));
    report(40);
    await vi.waitFor(() => expect((screen.getByLabelText(/Uploading/) as HTMLProgressElement).value).toBe(40));
    // Escape is ignored while uploading.
    await user.keyboard('{Escape}');
    expect(screen.getByRole('dialog')).toBeTruthy();

    finish({ id: 1 });
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByTestId('done').textContent).toBe('{"id":1}');
    expect(document.activeElement).toBe(opener);
  });

  it('shows a server 422 under the field and other errors in a banner', async () => {
    const user = userEvent.setup();
    const upload = vi
      .fn()
      .mockRejectedValueOnce(new ApiError(422, { message: 'x', errors: { file: ['The file is not a supported image.'] } }))
      .mockRejectedValueOnce(new ApiError(429, null));
    render(harness(upload));
    await user.click(screen.getByRole('button', { name: 'Upload logo' }));
    await user.upload(await screen.findByLabelText('Choose an image'), png());
    await user.click(screen.getByRole('button', { name: 'Upload' }));
    expect(await screen.findByText('The file is not a supported image.')).toBeTruthy();
    expect(screen.getByLabelText('Choose an image').getAttribute('aria-invalid')).toBe('true');

    await user.click(screen.getByRole('button', { name: 'Upload' }));
    expect((await screen.findByRole('alert')).textContent).toMatch(/Wait a minute/);
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
