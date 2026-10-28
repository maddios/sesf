import { describe, expect, it } from 'vitest';
import { parsePrefill } from './prefill';

describe('parsePrefill', () => {
    it('keeps the full email from the endpoint', () => {
        expect(parsePrefill({ ok: true, first: 'Jane', last: 'Doe', email: 'jane.doe@example.com', csrf: 'x' })).toEqual({
            first: 'Jane',
            last: 'Doe',
            email: 'jane.doe@example.com',
        });
    });

    it('accepts an empty email (walk-in has none on file)', () => {
        expect(parsePrefill({ ok: true, first: '', last: '', email: '' })).toEqual({ first: '', last: '', email: '' });
    });

    it('rejects a body without the expected fields', () => {
        expect(parsePrefill({ ok: false, error: 'Invalid waiver link.' })).toBeNull();
        expect(parsePrefill(null)).toBeNull();
    });
});
