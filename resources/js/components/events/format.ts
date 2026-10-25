/**
 * "2026-04-30" to "Apr 30, 2026" (UTC, so no timezone shifts a calendar date). Anything that is not a plain
 * date is returned unchanged, trimmed.
 */
export function formatEventDate(value: string): string {
  const text = value.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) {
    return text;
  }
  const date = new Date(`${text}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) {
    return text;
  }
  return date.toLocaleDateString('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric' });
}

/** "2026-04-30" and "2026-05-03" to "Apr 30, 2026 - May 3, 2026"; missing ends are dropped. */
export function formatEventRange(start: string, end: string): string {
  const from = formatEventDate(start);
  const to = formatEventDate(end);
  if (from === '') {
    return to;
  }
  if (to === '' || to === from) {
    return from;
  }
  return `${from} - ${to}`;
}
