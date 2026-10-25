/**
 * Money helpers for invoices. Amounts stay decimal strings end to end; nothing goes through floating point.
 */

const DECIMAL_PATTERN = /^(-?)(\d+)(?:\.(\d{1,2}))?$/;

/** Groups the integer digits with commas: "1234567" -> "1,234,567". */
function group(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Display form for a decimal string: "1234.5" -> "$1,234.50", "-3" -> "-$3.00". Invalid input shows an em dash.
 */
export function formatMoney(value: string): string {
  const match = DECIMAL_PATTERN.exec(value.trim());
  if (match === null) {
    return '—';
  }
  const [, sign, whole, fraction = ''] = match;
  const cents = fraction.padEnd(2, '0');
  return `${sign === '-' ? '-' : ''}$${group(whole)}.${cents}`;
}

/** Display form of a credit (a negative owed amount), without the minus sign: "-3.00" -> "$3.00". */
export function formatCredit(value: string): string {
  return formatMoney(value.replace(/^-/, ''));
}

/** Whether an amount is more than zero (it is owed). */
export function isPositiveMoney(value: string): boolean {
  const match = DECIMAL_PATTERN.exec(value.trim());
  return match !== null && match[1] === '' && /[1-9]/.test(match[2] + (match[3] ?? ''));
}

/** Whether an amount is less than zero (a credit). */
export function isNegativeMoney(value: string): boolean {
  const match = DECIMAL_PATTERN.exec(value.trim());
  return match !== null && match[1] === '-' && /[1-9]/.test(match[2] + (match[3] ?? ''));
}

/**
 * The PayPal `amount` field: a plain number with "." and two decimals and no separators, so "1000.00" stays
 * "1000.00". Returns '' for anything that is not a positive amount.
 */
export function paypalAmount(owed: string): string {
  const match = DECIMAL_PATTERN.exec(owed.trim());
  if (match === null || match[1] === '-' || !isPositiveMoney(owed)) {
    return '';
  }
  return `${match[2]}.${(match[3] ?? '').padEnd(2, '0')}`;
}
