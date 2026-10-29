import { afterEach, describe, expect, it, vi } from 'vitest';
import { AutosaveQueue } from '../autosave';
import { ok } from './fixtures';

describe('AutosaveQueue', () => {
  afterEach(() => vi.useRealTimers());

  it('merges debounced changes into one request after the idle time', async () => {
    vi.useFakeTimers();
    const send = vi.fn(async () => ok);
    const queue = new AutosaveQueue(send, vi.fn(), 1000);

    queue.queue({ comment_1: 'a' }, true);
    queue.queue({ comment_1: 'ab', q_1: 5 }, true);
    await vi.advanceTimersByTimeAsync(999);
    expect(send).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    expect(send).toHaveBeenCalledExactlyOnceWith({ comment_1: 'ab', q_1: 5 });
  });

  it('sends one request at a time and flushes what queued meanwhile', async () => {
    let release: () => void = () => undefined;
    const send = vi
      .fn()
      .mockImplementationOnce(() => new Promise((resolve) => (release = () => resolve(ok))))
      .mockResolvedValue(ok);
    const queue = new AutosaveQueue(send, vi.fn());

    const first = queue.flush();
    queue.queue({ q_1: 1 }, false);
    const firstFlush = queue.flush();
    queue.queue({ q_2: 2 }, false);
    const second = queue.flush();
    await Promise.resolve();
    expect(send).toHaveBeenCalledTimes(1);

    release();
    await Promise.all([first, firstFlush, second]);
    expect(send).toHaveBeenCalledTimes(2);
    expect(send).toHaveBeenNthCalledWith(1, { q_1: 1 });
    expect(send).toHaveBeenNthCalledWith(2, { q_2: 2 });
  });

  it('reports a failed save and keeps working', async () => {
    const onError = vi.fn();
    const send = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(ok);
    const queue = new AutosaveQueue(send, onError);

    queue.queue({ q_1: 1 }, false);
    await queue.flush();
    queue.queue({ q_1: 2 }, false);
    await queue.flush();

    expect(onError).toHaveBeenCalledOnce();
    expect(send).toHaveBeenLastCalledWith({ q_1: 2 });
  });
});
