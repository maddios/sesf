import { describe, it, expect } from 'vitest';
import { formatEventDate, formatEventRange } from '../format';

describe('formatEventDate', () => {
  it('formats plain calendar dates in UTC', () => {
    expect(formatEventDate('2026-04-30')).toBe('Apr 30, 2026');
    expect(formatEventDate(' 2026-05-03 ')).toBe('May 3, 2026');
  });

  it('returns anything else unchanged', () => {
    expect(formatEventDate('TBA')).toBe('TBA');
    expect(formatEventDate('')).toBe('');
  });
});

describe('formatEventRange', () => {
  it('joins start and end, dropping a missing or repeated end', () => {
    expect(formatEventRange('2026-04-30', '2026-05-03')).toBe('Apr 30, 2026 - May 3, 2026');
    expect(formatEventRange('2026-04-30', '')).toBe('Apr 30, 2026');
    expect(formatEventRange('2026-04-30', '2026-04-30')).toBe('Apr 30, 2026');
    expect(formatEventRange('', '2026-05-03')).toBe('May 3, 2026');
  });
});
