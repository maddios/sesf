import { describe, expect, it, vi } from 'vitest';
import { addPhotoButtons } from '../photoButtons';

function pick(input: HTMLInputElement, files: File[]): void {
  Object.defineProperty(input, 'files', { value: files, configurable: true });
  input.dispatchEvent(new Event('change'));
}

describe('addPhotoButtons', () => {
  it('adds Take photo (rear camera) and Choose from gallery once', () => {
    const root = document.createElement('div');
    addPhotoButtons(root, true, vi.fn());
    addPhotoButtons(root, true, vi.fn());

    const buttons = [...root.querySelectorAll('button')].map((button) => button.textContent);
    const [camera, gallery] = [...root.querySelectorAll('input')];

    expect(buttons).toEqual(['Take photo', 'Choose from gallery']);
    expect(camera.getAttribute('capture')).toBe('environment');
    expect(camera.accept).toBe('image/*');
    expect(gallery.multiple).toBe(true);
    expect(gallery.hasAttribute('capture')).toBe(false);
  });

  it('passes picked files on and opens the chooser from the button', () => {
    const root = document.createElement('div');
    const onFiles = vi.fn();
    addPhotoButtons(root, false, onFiles);
    const gallery = root.querySelectorAll('input')[1];
    const click = vi.spyOn(gallery, 'click').mockImplementation(() => undefined);

    root.querySelectorAll('button')[1].click();
    expect(click).toHaveBeenCalled();
    expect(gallery.multiple).toBe(false);

    const photo = new File(['x'], 'p.jpg', { type: 'image/jpeg' });
    pick(gallery, [photo]);
    expect(onFiles).toHaveBeenCalledWith([photo]);

    pick(gallery, []);
    expect(onFiles).toHaveBeenCalledTimes(1);
  });
});
