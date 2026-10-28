import { beforeEach, describe, expect, it } from 'vitest';
import { clearPending, markCompleted, markPending, recoveryHint } from './recovery';

const URL = '/waiver_sign/t/abc';
const MINUTE = 60 * 1000;

beforeEach(() => {
    sessionStorage.clear();
});

describe('recoveryHint', () => {
    it('is empty with nothing stored', () => {
        expect(recoveryHint(URL, 1000)).toBe('');
    });

    it('warns about a submit still pending after 25 seconds', () => {
        markPending(URL, 0);
        expect(recoveryHint(URL, 10_000)).toBe('');
        expect(recoveryHint(URL, 26_000)).toContain('Open the waiver portal');
    });

    it('drops a pending submit older than 15 minutes', () => {
        markPending(URL, 0);
        expect(recoveryHint(URL, 16 * MINUTE)).toBe('');
        expect(sessionStorage.getItem('sesf_waiver_submit_pending_v1')).toBeNull();
    });

    it('explains a recent completion for the same link only', () => {
        markCompleted(URL, 'https://example.test/thanks', 0);
        expect(recoveryHint(URL, MINUTE)).toContain('recently reached the thank-you screen');
        expect(recoveryHint('/waiver_sign/t/other', MINUTE)).toBe('');
    });

    it('forgets a completion older than five minutes', () => {
        markCompleted(URL, 'https://example.test/thanks', 0);
        expect(recoveryHint(URL, 6 * MINUTE)).toBe('');
        expect(sessionStorage.getItem('sesf_waiver_completed_recent_v1')).toBeNull();
    });

    it('clears the pending marker on completion and on request', () => {
        markPending(URL, 0);
        markCompleted(URL, 'https://example.test/thanks', 0);
        expect(sessionStorage.getItem('sesf_waiver_submit_pending_v1')).toBeNull();
        markPending(URL, 0);
        clearPending();
        expect(sessionStorage.getItem('sesf_waiver_submit_pending_v1')).toBeNull();
    });
});
