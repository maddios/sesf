import { describe, expect, it, vi } from 'vitest';
import { formatBytes, formatEta, transferText, UPLOAD_STALLED_MS, UploadTracker } from '../uploads';

const file = (name: string, size: number) => new File([new Uint8Array(size)], name);

describe('UploadTracker', () => {
  it('reports bytes left, percent and ETA at the current rate', () => {
    let now = 0;
    const tracker = new UploadTracker(undefined, () => now);
    const a = tracker.start(file('a.jpg', 1000));
    const b = tracker.start(file('b.jpg', 3000));

    now = 2000;
    tracker.progress(a.id, 1000, 1000);
    tracker.progress(b.id, 1000, 3000);
    const state = tracker.state();

    expect(state).toMatchObject({ count: 2, percent: 50, remainingBytes: 2000, stalled: false });
    expect(state.etaSeconds).toBe(2);
    expect(state.files.map((f) => f.percent)).toEqual([100, 33]);
    expect(transferText(state)).toBe('Uploading images: 2 KB left, ETA 2s');
  });

  it('flags a stall after 15 s without progress', () => {
    let now = 0;
    const tracker = new UploadTracker(undefined, () => now);
    tracker.start(file('a.jpg', 1000));

    now = UPLOAD_STALLED_MS + 1;
    expect(tracker.state().stalled).toBe(true);
    expect(transferText(tracker.state())).toMatch(/^Upload stalled: /);
  });

  it('keeps failed files until dismissed and notifies on every change', () => {
    const onChange = vi.fn();
    const tracker = new UploadTracker(onChange);
    const { id } = tracker.start(file('a.jpg', 10));

    tracker.fail(id);
    expect(tracker.active).toBe(0);
    expect(tracker.state().failed).toEqual(['a.jpg']);

    tracker.dismissFailed();
    expect(tracker.state().failed).toEqual([]);
    expect(onChange).toHaveBeenCalledTimes(3);
  });

  it('stops every upload through its abort signal', () => {
    const tracker = new UploadTracker();
    const signals = [tracker.start(file('a.jpg', 1)).signal, tracker.start(file('b.jpg', 1)).signal];

    tracker.stopAll();

    expect(signals.every((signal) => signal.aborted)).toBe(true);
  });

  it('formats sizes and times like legacy', () => {
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(2048)).toBe('2 KB');
    expect(formatBytes(1.5 * 1024 * 1024)).toBe('1.5 MB');
    expect(formatEta(12.4)).toBe('12s');
    expect(formatEta(75)).toBe('1m 15s');
  });
});
