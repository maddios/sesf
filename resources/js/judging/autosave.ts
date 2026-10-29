import type { FieldValues, SaveResult } from './api';

/**
 * Collects changed fields and posts them one request at a time. A debounced change waits for
 * `idleMs` of quiet (typing a comment); an immediate one is sent at once (picking a score).
 */
export class AutosaveQueue {
  private pending: FieldValues = {};
  private timer: ReturnType<typeof setTimeout> | undefined;
  private inFlight: Promise<void> | undefined;

  constructor(
    private readonly send: (data: FieldValues) => Promise<SaveResult>,
    private readonly onError: (error: unknown) => void,
    private readonly idleMs = 1000,
  ) {}

  queue(fields: FieldValues, debounce: boolean): void {
    Object.assign(this.pending, fields);
    clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.flush(), debounce ? this.idleMs : 0);
  }

  /** Sends everything queued so far; resolves once it is saved (or failed and was reported). */
  async flush(): Promise<void> {
    clearTimeout(this.timer);
    this.timer = undefined;
    while (this.inFlight !== undefined) {
      await this.inFlight;
    }
    if (Object.keys(this.pending).length === 0) {
      return;
    }
    const data = this.pending;
    this.pending = {};
    this.inFlight = this.send(data)
      .then(() => undefined)
      .catch(this.onError)
      .finally(() => {
        this.inFlight = undefined;
      });
    await this.inFlight;
  }
}
