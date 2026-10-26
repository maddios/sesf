import { describe, it, expect } from 'vitest';
import { formatEventDate, formatEventRange, plainText } from '../format';

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

describe('plainText', () => {
  it('keeps plain text and strips (cut) HTML from the server fallback', () => {
    expect(plainText('The E2E sandbox show.')).toBe('The E2E sandbox show.');
    expect(plainText('<p>Last year in the sandbox.</p>...')).toBe('Last year in the sandbox....');
    expect(plainText('<p>Cut <strong>mid')).toBe('Cut mid');
    expect(plainText('Fish &amp; chips')).toBe('Fish & chips');
    expect(plainText('<img src=x onerror=alert(1)>Hi')).toBe('Hi');
  });
});
