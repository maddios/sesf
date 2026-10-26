/** Value helpers for the admin inputs. Money stays a decimal string; dates stay strings in the server's formats. */

const MONEY_PATTERN = /^\d{0,7}(\.\d{0,2})?$/;

/** "12" -> "12.00", "1.5" -> "1.50", ".5" -> "0.50", "$1,200" -> "1200.00"; null for anything that is not an amount. */
export function normaliseMoney(raw: string): string | null {
  const text = raw.trim().replace(/^\$/, '').replace(/,/g, '');
  if (text === '' || text === '.' || !MONEY_PATTERN.test(text)) {
    return null;
  }
  const [whole = '', fraction = ''] = text.split('.');
  return `${String(Number(whole === '' ? '0' : whole))}.${fraction.padEnd(2, '0')}`;
}

/** Server datetime ("2026-05-01 13:45:00" or ISO) -> <input type="datetime-local"> value ("2026-05-01T13:45"). */
export function toDateTimeLocal(value: string | null | undefined): string {
  const match = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(value ?? '');
  return match === null ? '' : `${match[1]}T${match[2]}`;
}

/** <input type="datetime-local"> value -> server datetime ("2026-05-01 13:45:00"); null when empty or malformed. */
export function fromDateTimeLocal(value: string): string | null {
  const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2})?$/.exec(value);
  return match === null ? null : `${match[1]} ${match[2]}${match[3] ?? ':00'}`;
}

/** Server date or datetime -> <input type="date"> value ("2026-05-01"). */
export function toDateInput(value: string | null | undefined): string {
  const match = /^(\d{4}-\d{2}-\d{2})/.exec(value ?? '');
  return match === null ? '' : (match[1] as string);
}
