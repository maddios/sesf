import type { ApiResult } from './http';

/** Legacy submit policy: 3 attempts, a 120 s request timeout, and transient failures retried with a growing delay. */
export const MAX_SUBMIT_ATTEMPTS = 3;
export const SUBMIT_TIMEOUT_MS = 120000;
const TRANSIENT_STATUSES: readonly number[] = [0, 408, 502, 503, 504];

export function isTransient(result: ApiResult): boolean {
    return result.timedOut === true || TRANSIENT_STATUSES.includes(result.status);
}

export function retryDelayMs(attempt: number): number {
    return attempt * 900;
}
