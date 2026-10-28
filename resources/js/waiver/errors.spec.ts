import { describe, expect, it } from 'vitest';
import { describeLoginFailure, describePrefillFailure, describeSignFailure } from './errors';

const result = (status: number, body: unknown = null) => ({ ok: status >= 200 && status < 300, status, body });

describe('describeSignFailure', () => {
    it('shows the server message for a validation failure', () => {
        expect(describeSignFailure(result(400, { ok: false, error: 'Email is required.' }))).toEqual({
            message: 'Email is required.',
            completed: false,
        });
    });

    it('uses the legacy 400 hint when the server gives no message', () => {
        expect(describeSignFailure(result(400)).message).toBe('This page may have expired. Refresh the page, then sign and submit again.');
    });

    it('marks 409 as completed with the legacy already-on-file message', () => {
        expect(describeSignFailure(result(409))).toEqual({
            message: 'This waiver is already on file—you do not need to sign again. Refresh the waiver portal to see your status.',
            completed: true,
        });
    });

    it('uses the legacy 429 message', () => {
        expect(describeSignFailure(result(429)).message).toBe(
            'Too many submit attempts from this network. Wait about one minute, refresh the page, then try once more.',
        );
    });

    it('falls back to the legacy server and request messages', () => {
        expect(describeSignFailure(result(500, { error: 'We could not finish saving.' })).message).toBe('We could not finish saving.');
        expect(describeSignFailure(result(503)).message).toContain('Server error. Wait a moment');
        expect(describeSignFailure(result(404)).message).toBe('Request failed. Check your connection, refresh if needed, and tap Submit again.');
    });
});

describe('describePrefillFailure', () => {
    it('uses the server error or the legacy boot message', () => {
        expect(describePrefillFailure(result(404, { ok: false, error: 'Invalid waiver link.' }))).toBe('Invalid waiver link.');
        expect(describePrefillFailure(result(0))).toBe('Could not load waiver. Check your connection and refresh the page.');
    });
});

describe('describeLoginFailure', () => {
    it('shows the first message from the login array', () => {
        expect(describeLoginFailure(result(401, ['Either your email or password are incorrect']))).toBe(
            'Either your email or password are incorrect',
        );
    });

    it('maps throttling and network failures', () => {
        expect(describeLoginFailure(result(429))).toContain('Too many sign-in attempts');
        expect(describeLoginFailure(result(0))).toContain('Connection problem');
    });
});
