import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import BaseModal from '../BaseModal.vue';

/** A page with an opener button and a controlled modal holding two form controls. */
const Harness = defineComponent({
  setup() {
    const open = ref(false);
    const closes = ref(0);
    return () => [
      h('button', { type: 'button', onClick: () => (open.value = true) }, 'Open dialog'),
      h('p', `closed ${closes.value}`),
      h(
        BaseModal,
        {
          open: open.value,
          title: 'Contact us',
          'onUpdate:open': (value: boolean) => (open.value = value),
          onClose: () => (closes.value += 1),
        },
        {
          default: () => [h('label', { for: 'm-name' }, 'Name'), h('input', { id: 'm-name' })],
          footer: () => [h('button', { type: 'button' }, 'Send')],
        },
      ),
    ];
  },
});

afterEach(() => {
  document.body.style.overflow = '';
});

describe('BaseModal', () => {
  it('opens as a labelled modal dialog, focuses the first control and locks scrolling', async () => {
    const user = userEvent.setup();
    render(Harness);
    expect(screen.queryByRole('dialog')).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Open dialog' }));

    const dialog = await screen.findByRole('dialog', { name: 'Contact us' });
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close dialog' }));
    expect(document.body.style.overflow).toBe('hidden');
  });

  it('traps Tab and Shift+Tab inside the dialog', async () => {
    const user = userEvent.setup();
    render(Harness);
    await user.click(screen.getByRole('button', { name: 'Open dialog' }));
    await screen.findByRole('dialog');
    const close = screen.getByRole('button', { name: 'Close dialog' });
    const send = screen.getByRole('button', { name: 'Send' });

    await user.tab();
    expect(document.activeElement).toBe(screen.getByLabelText('Name'));
    await user.tab();
    expect(document.activeElement).toBe(send);
    await user.tab();
    expect(document.activeElement).toBe(close);
    await user.tab({ shift: true });
    expect(document.activeElement).toBe(send);
  });

  it('closes on Escape, emits close, unlocks scrolling and returns focus to the opener', async () => {
    const user = userEvent.setup();
    render(Harness);
    const opener = screen.getByRole('button', { name: 'Open dialog' });
    await user.click(opener);
    await screen.findByRole('dialog');
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText('closed 1')).toBeTruthy();
    expect(document.body.style.overflow).toBe('');
    expect(document.activeElement).toBe(opener);
  });

  it('closes from the close button and from a backdrop click', async () => {
    const user = userEvent.setup();
    render(Harness);
    await user.click(screen.getByRole('button', { name: 'Open dialog' }));
    await user.click(await screen.findByRole('button', { name: 'Close dialog' }));
    expect(screen.queryByRole('dialog')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Open dialog' }));
    await user.click(await screen.findByTestId('modal-backdrop'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText('closed 2')).toBeTruthy();
  });

  it('keeps focus on the only control and ignores the backdrop when asked to', async () => {
    const user = userEvent.setup();
    // Open after mount so the open watcher runs.
    const { rerender } = render(BaseModal, { props: { open: false, title: 'Info', closeOnBackdrop: false } });
    await rerender({ open: true, title: 'Info', closeOnBackdrop: false });
    await screen.findByRole('dialog', { name: 'Info' });
    const close = screen.getByRole('button', { name: 'Close dialog' });
    expect(document.activeElement).toBe(close);
    await user.tab();
    expect(document.activeElement).toBe(close);
    await user.tab({ shift: true });
    expect(document.activeElement).toBe(close);
    await user.click(screen.getByTestId('modal-backdrop'));
    expect(screen.getByRole('dialog', { name: 'Info' })).toBeTruthy();
  });

  it('releases the scroll lock if unmounted while open', async () => {
    const { rerender, unmount } = render(BaseModal, { props: { open: false, title: 'Gone' } });
    await rerender({ open: true, title: 'Gone' });
    expect(document.body.style.overflow).toBe('hidden');
    unmount();
    expect(document.body.style.overflow).toBe('');
  });

  it('has no detectable accessibility violations when open', async () => {
    const user = userEvent.setup();
    render(Harness);
    await user.click(screen.getByRole('button', { name: 'Open dialog' }));
    await screen.findByRole('dialog');
    // The bare test page has no landmarks; that is the harness, not the dialog.
    expect(await axe(document.body, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });
});
