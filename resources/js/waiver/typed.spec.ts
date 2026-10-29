import { describe, expect, it } from 'vitest';
import { fitFontSize } from './typed';

describe('fitFontSize', () => {
    it('shrinks in steps of 2 from 88px until the text fits', () => {
        expect(fitFontSize(500, (size) => size * 10)).toBe(50);
    });

    it('keeps the size when the text already fits', () => {
        expect(fitFontSize(10_000, (size) => size)).toBe(88);
    });

    it('never goes below 28px', () => {
        expect(fitFontSize(1, (size) => size * 100)).toBe(28);
    });
});
