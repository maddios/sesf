import { describe, expect, it, vi } from 'vitest';
import { ApiError, type ApiClient } from '../../api/client';
import { createJudgingApi, errorMessages, postWithProgress, UploadAborted } from '../api';
import { ok } from './fixtures';

function client(post: ApiClient['post']): ApiClient {
  return { get: vi.fn(), post, put: vi.fn(), patch: vi.fn(), delete: vi.fn(), request: vi.fn() } as unknown as ApiClient;
}

describe('judging api', () => {
  it('posts autosaves as {data} and photos as data[file_N][] with progress', async () => {
    const xhrs = stubUploadXhr();
    const stored = { name: 'a.jpg', serverFile: 'a.jpg', content: '/images/judging/1/2/a.jpg', type: 'image/jpeg' };
    const post = vi.fn().mockResolvedValue(ok);
    const api = createJudgingApi('/judgingData/x', '/judgingSubmit/x', client(post));

    await api.save({ q_2: 5 });
    expect(post).toHaveBeenCalledWith('/judgingSubmit/x', { data: { q_2: 5 } });

    const onProgress = vi.fn();
    const upload = api.upload('file_2', new File(['x'], 'p.jpg'), { onProgress });
    const xhr = xhrs[0];
    xhr.progress(5, 10);
    xhr.respond(200, { ...ok, uploadedFiles: { file_2: [stored] } });

    await expect(upload).resolves.toEqual(stored);
    expect([xhr.method, xhr.url]).toEqual(['POST', '/judgingSubmit/x']);
    expect(xhr.headers.Accept).toBe('application/json');
    expect((xhr.body as FormData).getAll('data[file_2][]')).toHaveLength(1);
    expect(onProgress).toHaveBeenCalledWith(5, 10);
  });

  it('fails an upload the server did not store or refused', async () => {
    const xhrs = stubUploadXhr();
    const api = createJudgingApi('/d', '/s', client(vi.fn()));

    const missing = api.upload('file_2', new File(['x'], 'p.jpg'));
    xhrs[0].respond(200, ok);
    await expect(missing).rejects.toThrow('did not store');

    const refused = postWithProgress('/s', new FormData());
    xhrs[1].respond(400, { errors: ['The image is larger than 12 MB.'] });
    await expect(refused).rejects.toBeInstanceOf(ApiError);

    const offline = postWithProgress('/s', new FormData());
    xhrs[2].onerror?.();
    await expect(offline).rejects.toBeInstanceOf(ApiError);
  });

  it('stops an upload through its abort signal', async () => {
    const xhrs = stubUploadXhr();
    const controller = new AbortController();

    const upload = postWithProgress('/s', new FormData(), { signal: controller.signal });
    controller.abort();

    await expect(upload).rejects.toBeInstanceOf(UploadAborted);
    expect(xhrs[0].aborted).toBe(true);
  });

  it('turns server errors into readable messages', () => {
    expect(errorMessages(new ApiError(400, { errors: ['Please complete page 1 before continuing.'] }))).toEqual(['Please complete page 1 before continuing.']);
    expect(errorMessages(new ApiError(401, {}))[0]).toMatch(/session may have expired/);
    expect(errorMessages(new ApiError(419, {}))[0]).toMatch(/session may have expired/);
    expect(errorMessages(new ApiError(500, { message: 'Server Error' }))).toEqual(['Server Error']);
    expect(errorMessages(new TypeError('Failed to fetch'))[0]).toMatch(/check your connection/);
  });
});

interface UploadXhr {
  method: string;
  url: string;
  headers: Record<string, string>;
  body: unknown;
  aborted: boolean;
  onerror: (() => void) | null;
  progress(loaded: number, total: number): void;
  respond(status: number, body: unknown): void;
}

function stubUploadXhr(): UploadXhr[] {
  const created: UploadXhr[] = [];
  class Fake {
    method = '';
    url = '';
    headers: Record<string, string> = {};
    body: unknown = null;
    status = 0;
    responseText = '';
    withCredentials = false;
    aborted = false;
    upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    onabort: (() => void) | null = null;
    constructor() {
      created.push(this);
    }
    open(method: string, url: string) {
      this.method = method;
      this.url = url;
    }
    setRequestHeader(name: string, value: string) {
      this.headers[name] = value;
    }
    send(body: unknown) {
      this.body = body;
    }
    abort() {
      this.aborted = true;
      this.onabort?.();
    }
    progress(loaded: number, total: number) {
      this.upload.onprogress?.({ lengthComputable: true, loaded, total } as ProgressEvent);
    }
    respond(status: number, body: unknown) {
      this.status = status;
      this.responseText = JSON.stringify(body);
      this.onload?.();
    }
  }
  vi.stubGlobal('XMLHttpRequest', Fake);
  return created;
}
