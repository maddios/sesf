import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import TransferStatus from '../TransferStatus.vue';
import type { TransferState } from '../uploads';

const uploading: TransferState = {
  count: 1,
  percent: 40,
  remainingBytes: 3 * 1024 * 1024,
  etaSeconds: 9,
  stalled: false,
  files: [{ id: 1, name: 'door.jpg', percent: 40 }],
  failed: [],
};

describe('TransferStatus', () => {
  it('shows progress, time left and a stop button', async () => {
    const { container, emitted } = render(TransferStatus, { props: { state: uploading } });

    expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('40');
    expect(screen.getByText('Uploading images: 3.0 MB left, ETA 9s')).toBeTruthy();
    expect(screen.getByText('door.jpg: 40%')).toBeTruthy();

    await userEvent.click(screen.getByRole('button', { name: 'Stop photo uploads' }));
    expect(emitted().stop).toHaveLength(1);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows a stall and failed files', async () => {
    const { emitted } = render(TransferStatus, { props: { state: { ...uploading, stalled: true, failed: ['hood.jpg'] } } });

    expect(screen.getByText(/^Upload stalled: /)).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('Upload failed — try again: hood.jpg');

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(emitted().dismiss).toHaveLength(1);
  });

  it('renders nothing when idle', () => {
    const { container } = render(TransferStatus, { props: { state: { ...uploading, count: 0, files: [] } } });

    expect(container.querySelector('section')).toBeNull();
  });
});
