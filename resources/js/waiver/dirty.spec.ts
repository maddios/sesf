import { describe, expect, it } from 'vitest';
import { isSignatureDirty } from './dirty';

describe('isSignatureDirty', () => {
    it('is clean with nothing drawn or typed', () => {
        expect(isSignatureDirty(false, false, '', '  ')).toBe(false);
    });

    it('is dirty with an accepted signature or typed text that is not submitted', () => {
        expect(isSignatureDirty(false, false, 'data:image/png;base64,AA', '')).toBe(true);
        expect(isSignatureDirty(false, false, '', 'Ada')).toBe(true);
    });

    it('is clean once submitted or while submitting', () => {
        expect(isSignatureDirty(true, false, 'data:image/png;base64,AA', 'Ada')).toBe(false);
        expect(isSignatureDirty(false, true, 'data:image/png;base64,AA', 'Ada')).toBe(false);
    });
});
