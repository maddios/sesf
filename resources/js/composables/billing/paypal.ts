import { paypalAmount } from './money';

/**
 * Fields of the PayPal "Buy Now" form (cmd=_xclick), as the legacy invoice sent them, plus notify_url so the IPN
 * reaches this app (App\Services\PayPal, docs/paypal-ipn.md).
 */
export interface PaypalFieldInput {
  /** PayPal receiver (config paypalEmail). */
  email: string;
  /** Public base URL of this app (config appUrl), without a trailing slash. */
  appUrl: string;
  registrationId: number;
  /** Owed amount as a decimal string from the server. */
  owed: string;
  /** Absolute URL to return to after payment (the current page). */
  returnUrl: string;
}

export const PAYPAL_WEBSCR_URL = 'https://www.paypal.com/cgi-bin/webscr';

/** The form fields, or null when there is nothing to pay or the required settings are missing. */
export function buildPaypalFields(input: PaypalFieldInput): Record<string, string> | null {
  const amount = paypalAmount(input.owed);
  if (amount === '' || input.email === '' || input.appUrl === '' || input.registrationId <= 0) {
    return null;
  }
  return {
    cmd: '_xclick',
    business: input.email,
    item_name: 'Registration fee',
    item_number: String(input.registrationId),
    amount,
    currency_code: 'USD',
    notify_url: `${input.appUrl}/paypal-ipn`,
    return: input.returnUrl,
    no_shipping: '1',
  };
}
