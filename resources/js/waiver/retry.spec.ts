import { describe, expect, it } from 'vitest';
import { isTransient, MAX_SUBMIT_ATTEMPTS, retryDelayMs, SUBMIT_TIMEOUT_MS } from './retry';

const result = (status: number, timedOut?: boolean) => ({ ok: false, status, body: null, timedOut });

describe('isTransient', () => {
    it('retries network failures, timeouts and gateway errors', () => {
        expect(isTransient(result(0))).toBe(true);
        expect(isTransient(result(0, true))).toBe(true);
        expect(isTransient(result(408))).toBe(true);
        expect(isTransient(result(502))).toBe(true);
        expect(isTransient(result(503))).toBe(true);
        expect(isTransient(result(504))).toBe(true);
    });

    it('does not retry validation, conflict or server errors', () => {
        expect(isTransient(result(400))).toBe(false);
        expect(isTransient(result(409))).toBe(false);
        expect(isTransient(result(500))).toBe(false);
    });
});

describe('submit policy', () => {
    it('matches the legacy 3 attempts, 120 s timeout and growing delay', () => {
        expect(MAX_SUBMIT_ATTEMPTS).toBe(3);
        expect(SUBMIT_TIMEOUT_MS).toBe(120000);
        expect([retryDelayMs(1), retryDelayMs(2)]).toEqual([900, 1800]);
    });
});
