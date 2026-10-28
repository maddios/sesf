export const UPLOAD_STALLED_MS = 15000;

interface Upload {
  name: string;
  total: number;
  loaded: number;
  startedAt: number;
  lastProgressAt: number;
  controller: AbortController;
}

export interface UploadFileState {
  id: number;
  name: string;
  percent: number;
}

export interface TransferState {
  count: number;
  percent: number;
  remainingBytes: number;
  etaSeconds: number | null;
  stalled: boolean;
  files: UploadFileState[];
  failed: string[];
}

/**
 * Photo uploads in flight (legacy activeUploads and the transfer status bar): byte progress, time left
 * at the current rate, a stall flag after 15 s without progress, failed files, and stop-all.
 */
export class UploadTracker {
  private readonly uploads = new Map<number, Upload>();
  private failedNames: string[] = [];
  private sequence = 0;

  constructor(
    private readonly onChange: () => void = () => undefined,
    private readonly now: () => number = () => Date.now(),
  ) {}

  get active(): number {
    return this.uploads.size;
  }

  start(file: File): { id: number; signal: AbortSignal } {
    const id = ++this.sequence;
    const at = this.now();
    const controller = new AbortController();
    this.uploads.set(id, { name: file.name, total: file.size, loaded: 0, startedAt: at, lastProgressAt: at, controller });
    this.failedNames = this.failedNames.filter((name) => name !== file.name);
    this.onChange();
    return { id, signal: controller.signal };
  }

  progress(id: number, loaded: number, total: number): void {
    const upload = this.uploads.get(id);
    if (upload === undefined) {
      return;
    }
    upload.loaded = Math.max(upload.loaded, loaded);
    upload.total = total || upload.total;
    upload.lastProgressAt = this.now();
    this.onChange();
  }

  finish(id: number): void {
    this.uploads.delete(id);
    this.onChange();
  }

  fail(id: number): void {
    const upload = this.uploads.get(id);
    if (upload !== undefined) {
      this.failedNames.push(upload.name);
    }
    this.finish(id);
  }

  stopAll(): void {
    for (const upload of this.uploads.values()) {
      upload.controller.abort();
    }
  }

  dismissFailed(): void {
    this.failedNames = [];
    this.onChange();
  }

  state(): TransferState {
    const uploads = [...this.uploads.entries()];
    const now = this.now();
    const total = uploads.reduce((sum, [, upload]) => sum + upload.total, 0);
    const loaded = uploads.reduce((sum, [, upload]) => sum + Math.min(upload.loaded, upload.total), 0);
    const elapsed = uploads.length === 0 ? 0 : Math.max(1, (now - Math.min(...uploads.map(([, upload]) => upload.startedAt))) / 1000);
    const rate = elapsed > 0 ? loaded / elapsed : 0;
    const remainingBytes = Math.max(0, total - loaded);

    return {
      count: uploads.length,
      percent: total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0,
      remainingBytes,
      etaSeconds: rate > 0 ? remainingBytes / rate : null,
      stalled: uploads.some(([, upload]) => now - upload.lastProgressAt > UPLOAD_STALLED_MS),
      files: uploads.map(([id, upload]) => ({ id, name: upload.name, percent: upload.total > 0 ? Math.round((upload.loaded / upload.total) * 100) : 0 })),
      failed: [...this.failedNames],
    };
  }
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1024 * 1024) {
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  }
  if (bytes >= 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }
  return `${bytes} B`;
}

export function formatEta(seconds: number): string {
  const whole = Math.max(0, Math.round(seconds));
  return whole >= 60 ? `${Math.floor(whole / 60)}m ${whole % 60}s` : `${whole}s`;
}

/** The legacy status bar text, e.g. "Uploading images: 1.5 MB left, ETA 12s". */
export function transferText(state: TransferState): string {
  const eta = state.etaSeconds === null ? '' : `, ETA ${formatEta(state.etaSeconds)}`;
  return `${state.stalled ? 'Upload stalled' : 'Uploading images'}: ${formatBytes(state.remainingBytes)} left${eta}`;
}
