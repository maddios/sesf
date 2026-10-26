import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { useToast, TOAST_TIMEOUT_MS } from '../useToast';
import { useConfirm } from '../useConfirm';
import AdminToastRegion from '../../../components/admin/AdminToastRegion.vue';
import AdminConfirmHost from '../../../components/admin/AdminConfirmHost.vue';

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  vi.useRealTimers();
});

describe('useToast + AdminToastRegion', () => {
  it('announces toasts in a polite live region and auto-dismisses success after the timeout', async () => {
    vi.useFakeTimers();
    render(AdminToastRegion);
    const region = screen.getByRole('status', { name: 'Notifications' });
    expect(region.getAttribute('aria-live')).toBe('polite');

    useToast().success('Saved.');
    await vi.advanceTimersByTimeAsync(0);
    expect(region.textContent).toContain('Saved.');
    await vi.advanceTimersByTimeAsync(TOAST_TIMEOUT_MS);
    expect(region.textContent).not.toContain('Saved.');
  });

  it('keeps errors until dismissed and passes axe', async () => {
    const user = userEvent.setup();
    const { container } = render(AdminToastRegion);
    const toast = useToast();
    toast.error('Failed.');
    toast.info('FYI.');
    expect(await screen.findByText('Failed.')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getAllByRole('button', { name: 'Dismiss notification' })[0]!);
    expect(screen.queryByText('Failed.')).toBeNull();
    expect(toast.toasts.value.map((t) => t.message)).toEqual(['FYI.']);
    toast.dismiss(999);
  });
});

describe('useConfirm + AdminConfirmHost', () => {
  it('resolves true on confirm and false on cancel', async () => {
    const user = userEvent.setup();
    render(AdminConfirmHost);
    const { confirm } = useConfirm();

    const yes = confirm({ title: 'Publish', message: 'Publish now?', confirmLabel: 'Publish' });
    await user.click(await screen.findByRole('button', { name: 'Publish' }));
    await expect(yes).resolves.toBe(true);

    const no = confirm({ title: 'Publish', message: 'Again?' });
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));
    await expect(no).resolves.toBe(false);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('a second request cancels the first one', async () => {
    const { confirm } = useConfirm();
    const first = confirm({ title: 'A', message: 'a' });
    const second = confirm({ title: 'B', message: 'b' });
    await expect(first).resolves.toBe(false);
    useConfirm().settle(true);
    await expect(second).resolves.toBe(true);
  });
});
