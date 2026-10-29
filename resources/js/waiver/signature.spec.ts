import { describe, expect, it } from 'vitest';
import { canvasPixelSize, hasInk, nextPenWidth, PEN } from './signature';

describe('hasInk', () => {
    it('is false until a stroke has a point', () => {
        expect(hasInk([])).toBe(false);
        expect(hasInk([[]])).toBe(false);
    });

    it('is true for a single tap', () => {
        expect(hasInk([[{ x: 0.5, y: 0.5, width: 2, time: 0 }]])).toBe(true);
    });
});

describe('canvasPixelSize', () => {
    it('uses the device pixel ratio when the result fits the server cap', () => {
        expect(canvasPixelSize(600, 200, 2)).toEqual({ width: 1200, height: 400 });
    });

    it('never exceeds the 2000x800 pixel cap', () => {
        const size = canvasPixelSize(1200, 500, 3);
        expect(size.width).toBeLessThanOrEqual(2000);
        expect(size.height).toBeLessThanOrEqual(800);
    });
});

describe('nextPenWidth', () => {
    it('stays within the legacy min and max widths', () => {
        for (const speed of [0, 0.1, 1, 10]) {
            const width = nextPenWidth(1.5, speed * 10, 10);
            expect(width).toBeGreaterThanOrEqual(PEN.minWidth);
            expect(width).toBeLessThanOrEqual(PEN.maxWidth);
        }
    });

    it('draws slow strokes thicker than fast strokes', () => {
        expect(nextPenWidth(PEN.minWidth, 0, 100)).toBeGreaterThan(nextPenWidth(PEN.minWidth, 100, 10));
    });
});
