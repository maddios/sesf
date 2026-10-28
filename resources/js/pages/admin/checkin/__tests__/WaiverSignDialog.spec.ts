import { afterEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { defineComponent, h } from 'vue';
import WaiverSignDialog from '../components/WaiverSignDialog.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import { useConfirm } from '../../../../composables/admin/useConfirm';

const SIGNED = { type: 'sesf-portal-waiver-signed' };

function mountDialog() {
  const closed: number[] = [];
  const Host = defineComponent({
    render: () => [
      h(WaiverSignDialog, { open: true, title: 'Ada — Waiver', url: 'http://localhost/waiver_sign/t/abc', onClose: () => closed.push(1) }),
      h(AdminConfirmHost),
    ],
  });
  render(Host);

  return closed;
}

async function frameWindow(): Promise<Window> {
  const frame = (await screen.findByTitle('Ada — Waiver')) as HTMLIFrameElement;
  return frame.contentWindow as Window;
}

function post(source: Window | null, origin: string, data: unknown): void {
  window.dispatchEvent(new MessageEvent('message', { data, origin, source }));
}

afterEach(() => {
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('WaiverSignDialog', () => {
  it('loads the sign page in embedded mode', async () => {
    mountDialog();
    const frame = await screen.findByTitle('Ada — Waiver');
    expect(frame.getAttribute('src')).toBe('http://localhost/waiver_sign/t/abc?ui=embedded');
  });

  it('closes when the sign page of the same origin reports the signature', async () => {
    const closed = mountDialog();
    post(await frameWindow(), window.location.origin, SIGNED);
    await waitFor(() => expect(closed).toHaveLength(1));
  });

  it('ignores messages from another origin, another window or with another type', async () => {
    const closed = mountDialog();
    const sign = await frameWindow();
    post(sign, 'https://evil.example', SIGNED);
    post(window, window.location.origin, SIGNED);
    post(sign, window.location.origin, { type: 'other' });
    post(sign, window.location.origin, null);
    expect(closed).toHaveLength(0);
  });

  it('asks before closing over an unsubmitted signature and keeps it open on cancel', async () => {
    const closed = mountDialog();
    (await frameWindow() as Window & { sesfWaiverIsDirty?: () => boolean }).sesfWaiverIsDirty = () => true;
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: 'Close' }));
    expect(await screen.findByText(/signature you drew will be lost/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(closed).toHaveLength(0);

    await user.click(screen.getByRole('button', { name: 'Close' }));
    await user.click(await screen.findByRole('button', { name: 'Close waiver' }));
    await waitFor(() => expect(closed).toHaveLength(1));
  });

  it('closes without asking when nothing is drawn', async () => {
    const closed = mountDialog();
    await frameWindow();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Close' }));
    expect(closed).toHaveLength(1);
  });
});
