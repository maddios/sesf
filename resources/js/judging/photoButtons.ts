const MARKER = 'judging-photo-buttons';

/**
 * Adds the legacy "Take photo" (rear camera) and "Choose from gallery" buttons to a file question,
 * above SurveyJS's own file chooser. Calls onFiles with the picked files. Safe to call on every render.
 */
export function addPhotoButtons(root: HTMLElement, allowMultiple: boolean, onFiles: (files: File[]) => void): void {
  if (root.querySelector(`.${MARKER}`) !== null) {
    return;
  }
  const wrap = document.createElement('div');
  wrap.className = `${MARKER} mb-2 flex flex-wrap gap-2`;

  const add = (label: string, configure: (input: HTMLInputElement) => void) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.hidden = true;
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');
    configure(input);
    input.addEventListener('change', () => {
      const files = Array.from(input.files ?? []);
      input.value = '';
      if (files.length > 0) {
        onFiles(files);
      }
    });
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sd-btn';
    button.textContent = label;
    button.addEventListener('click', () => input.click());
    wrap.append(button, input);
  };

  add('Take photo', (input) => input.setAttribute('capture', 'environment'));
  add('Choose from gallery', (input) => {
    input.multiple = allowMultiple;
  });
  root.prepend(wrap);
}
