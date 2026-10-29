import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';

function harness(props: Record<string, unknown>) {
  return defineComponent({
    setup() {
      const open = ref(false);
      const log = ref<string[]>([]);
      return () => [
        h('button', { type: 'button', onClick: () => (open.value = true) }, 'Delete thing'),
        h('p', { 'data-testid': 'log' }, log.value.join(',')),
        h(ConfirmDialog, {
          open: open.value,
          title: 'Delete thing',
          message: 'This cannot be undone.',
          ...props,
          'onUpdate:open': (value: boolean) => (open.value = value),
          onConfirm: () => {
            log.value.push('confirm');
            open.value = false;
          },
          onCancel: () => log.value.push('cancel'),
        }),
      ];
    },
  });
}

afterEach(() => {
  document.body.style.overflow = '';
});

describe('ConfirmDialog', () => {
  it('focuses Cancel first for a danger dialog, confirms, and returns focus to the opener', async () => {
    const user = userEvent.setup();
    render(harness({ danger: true, confirmLabel: 'Delete' }));
    const opener = screen.getByRole('button', { name: 'Delete thing' });
    await user.click(opener);
    const dialog = await screen.findByRole('dialog', { name: 'Delete thing' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' }));
    expect(await axe(dialog)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByTestId('log').textContent).toBe('confirm');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(opener);
  });

  it('requires the typed confirmation text before enabling confirm', async () => {
    const user = userEvent.setup();
    render(harness({ danger: true, confirmLabel: 'Delete', typedConfirmation: 'DELETE' }));
    await user.click(screen.getByRole('button', { name: 'Delete thing' }));
    const input = await screen.findByLabelText(/Type DELETE to confirm/);
    expect(document.activeElement).toBe(input);
    const confirm = screen.getByRole('button', { name: 'Delete' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);

    await user.type(input, 'delete{Enter}');
    expect(screen.getByTestId('log').textContent).toBe('');
    await user.clear(input);
    await user.type(input, 'DELETE');
    expect(confirm.disabled).toBe(false);
    await user.keyboard('{Enter}');
    expect(screen.getByTestId('log').textContent).toBe('confirm');
  });

  it('cancels on Escape and focuses confirm first for a non-danger dialog', async () => {
    const user = userEvent.setup();
    render(harness({ confirmLabel: 'Publish' }));
    await user.click(screen.getByRole('button', { name: 'Delete thing' }));
    await screen.findByRole('dialog');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Publish' }));
    await user.keyboard('{Escape}');
    expect(screen.getByTestId('log').textContent).toBe('cancel');
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('disables both buttons while busy', () => {
    render(ConfirmDialog, { props: { open: true, title: 'T', message: 'M', busy: true } });
    expect((screen.getByRole('button', { name: 'Cancel' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: /Confirm/ }) as HTMLButtonElement).disabled).toBe(true);
  });
});
