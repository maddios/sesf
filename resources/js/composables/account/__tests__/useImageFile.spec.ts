import { afterEach, describe, it, expect, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { imageFileProblem, MAX_IMAGE_BYTES, useImageFile } from '../useImageFile';

function fileOf(type: string, size = 10): File {
  const file = new File(['x'], 'photo', { type });
  Object.defineProperty(file, 'size', { value: size });
  return file;
}

describe('imageFileProblem', () => {
  it('accepts jpeg, png, gif and webp up to 12 MB', () => {
    for (const type of ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) {
      expect(imageFileProblem(fileOf(type))).toBeNull();
    }
    expect(imageFileProblem(fileOf('image/png', MAX_IMAGE_BYTES))).toBeNull();
  });

  it('rejects other types and anything over 12 MB', () => {
    expect(imageFileProblem(fileOf('image/svg+xml'))).toMatch(/JPEG, PNG, GIF or WebP/);
    expect(imageFileProblem(fileOf('application/pdf'))).toMatch(/JPEG/);
    expect(imageFileProblem(fileOf('image/png', MAX_IMAGE_BYTES + 1))).toMatch(/12 MB/);
  });
});

describe('useImageFile', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function harness() {
    let api!: ReturnType<typeof useImageFile>;
    const wrapper = mount(
      defineComponent({
        setup() {
          api = useImageFile();
          return () => h('div');
        },
      }),
    );
    return { api, wrapper };
  }

  it('stores a valid file and builds a preview URL, revoking the previous one', async () => {
    const createObjectURL = vi.fn(() => 'blob:preview-1');
    const revokeObjectURL = vi.fn();
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }));
    const { api } = harness();

    const first = fileOf('image/png');
    expect(api.choose(first)).toBe(true);
    expect(api.file.value).toBe(first);
    expect(api.previewUrl.value).toBe('blob:preview-1');

    expect(api.choose(fileOf('image/jpeg'))).toBe(true);
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:preview-1');
  });

  it('rejects a bad file, keeps the problem message and clears the selection', () => {
    const { api } = harness();
    expect(api.choose(fileOf('image/png'))).toBe(true);
    expect(api.choose(fileOf('text/plain'))).toBe(false);
    expect(api.file.value).toBeNull();
    expect(api.problem.value).toMatch(/JPEG/);
  });

  it('clears on null and has no preview when createObjectURL is unavailable', async () => {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: undefined, revokeObjectURL: undefined }));
    const { api, wrapper } = harness();
    expect(api.choose(null)).toBe(false);
    expect(api.problem.value).toBeNull();
    api.choose(fileOf('image/gif'));
    expect(api.previewUrl.value).toBeNull();
    api.clear();
    await nextTick();
    expect(api.file.value).toBeNull();
    wrapper.unmount();
  });

  it('revokes the preview when the component unmounts', () => {
    const revokeObjectURL = vi.fn();
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:x', revokeObjectURL }));
    const { api, wrapper } = harness();
    api.choose(fileOf('image/webp'));
    wrapper.unmount();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:x');
  });
});
