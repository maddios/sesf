import { ApiError, createApiClient, readXsrfToken } from '../api/client';

export interface SurveyPage {
  name: string;
  title: string;
  elements: Array<Record<string, unknown>>;
}

export interface SheetData {
  form: SurveyPage[];
  showPage: number;
}

export interface ServerFile {
  name: string;
  serverFile: string;
  content: string;
  type: string;
}

export interface SaveResult {
  success: boolean;
  savedQuestionIds: number[];
  firstIncompletePage: number | null;
  uploadedFiles: Record<string, ServerFile[]>;
}

export type FieldValues = Record<string, unknown>;

export interface UploadOptions {
  onProgress?: (loaded: number, total: number) => void;
  signal?: AbortSignal;
}

export interface JudgingApi {
  load(): Promise<SheetData>;
  save(data: FieldValues): Promise<SaveResult>;
  upload(fieldName: string, file: File, options?: UploadOptions): Promise<ServerFile>;
}

export class UploadAborted extends Error {
  constructor() {
    super('Upload stopped.');
    this.name = 'UploadAborted';
  }
}

function parseBody(text: string): unknown {
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

/** Multipart POST with byte progress and abort (fetch reports neither). Same headers as the api client. */
export function postWithProgress<T>(url: string, form: FormData, options: UploadOptions = {}): Promise<T> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const token = readXsrfToken();
    if (token !== undefined) {
      xhr.setRequestHeader('X-XSRF-TOKEN', token);
    }
    xhr.upload.onprogress = (event: ProgressEvent) => {
      if (event.lengthComputable) {
        options.onProgress?.(event.loaded, event.total);
      }
    };
    xhr.onload = () => {
      const body = parseBody(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(body as T);
      } else {
        reject(new ApiError(xhr.status, body));
      }
    };
    xhr.onerror = () => reject(new ApiError(0, null, 'Network error'));
    xhr.onabort = () => reject(new UploadAborted());
    options.signal?.addEventListener('abort', () => xhr.abort(), { once: true });
    xhr.send(form);
  });
}

export function createJudgingApi(dataUrl: string, submitUrl: string, client = createApiClient()): JudgingApi {
  return {
    load: () => client.get<SheetData>(dataUrl),
    save: (data) => client.post<SaveResult>(submitUrl, { data }),
    async upload(fieldName, file, options) {
      const form = new FormData();
      form.append(`data[${fieldName}][]`, file);
      const result = await postWithProgress<SaveResult>(submitUrl, form, options);
      const stored = result.uploadedFiles[fieldName]?.[0];
      if (stored === undefined) {
        throw new Error('The server did not store the photo.');
      }
      return stored;
    },
  };
}

/** The messages a failed judging request should show (legacy {errors: [...]} bodies). */
export function errorMessages(error: unknown): string[] {
  if (error instanceof ApiError) {
    const body = error.body as { errors?: unknown; message?: unknown } | null;
    if (body !== null && typeof body === 'object' && Array.isArray(body.errors) && body.errors.length > 0) {
      return body.errors.map(String);
    }
    if (error.status === 401 || error.isCsrfMismatch) {
      return ['Your session may have expired. Please reload this page and sign in again.'];
    }
    if (body !== null && typeof body === 'object' && typeof body.message === 'string') {
      return [body.message];
    }
  }
  return ['Could not save. Please check your connection and try again.'];
}
