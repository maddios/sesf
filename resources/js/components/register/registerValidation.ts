/** Client-side mirror of the POST /member/register rules. The server stays the authority. */
export interface RegisterValues {
  first_name: string;
  last_name: string;
  email: string;
  email_confirmation: string;
  street: string;
  street2: string;
  city: string;
  state: string;
  zip: string;
  phone: string;
  password: string;
  password_confirmation: string;
  accept_privacy_policy: boolean;
}

/** Field names used as error keys (the request fields, plus the Turnstile token). */
export type RegisterField = keyof RegisterValues | 'cf_turnstile_token';
export type RegisterErrors = Partial<Record<RegisterField, string>>;

/** Maximum lengths, matching the database column widths the backend enforces. */
export const FIELD_LIMITS = {
  first_name: 20,
  last_name: 20,
  email: 60,
  email_confirmation: 60,
  street: 60,
  street2: 60,
  city: 60,
  state: 10,
  zip: 10,
  phone: 12,
} as const;

export const PASSWORD_MIN_LENGTH = 6;
export const LATIN1_MESSAGE = 'Please use only standard letters (no emoji).';

export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const PHONE_PATTERN = /^\d{3}-\d{3}-\d{4}$/;

/** Field order on the form. Used to focus the first invalid field. */
export const FIELD_ORDER: readonly RegisterField[] = [
  'first_name',
  'last_name',
  'email',
  'email_confirmation',
  'street',
  'street2',
  'city',
  'state',
  'zip',
  'phone',
  'password',
  'password_confirmation',
  'accept_privacy_policy',
  'cf_turnstile_token',
];

/** True when every character fits in Latin-1 (code points up to U+00FF), i.e. no emoji or CJK text. */
export function isLatin1(value: string): boolean {
  for (const char of value) {
    if ((char.codePointAt(0) ?? 0) > 0xff) {
      return false;
    }
  }
  return true;
}

/** Formats phone input as ###-###-#### while typing: keeps the first ten digits, drops everything else. */
export function formatPhone(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 10);
  if (digits.length <= 3) {
    return digits;
  }
  if (digits.length <= 6) {
    return `${digits.slice(0, 3)}-${digits.slice(3)}`;
  }
  return `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6)}`;
}

function textProblems(value: string, label: string, limit: number): string | undefined {
  if (value.length > limit) {
    return `${label} must be ${limit} characters or fewer.`;
  }
  if (!isLatin1(value)) {
    return LATIN1_MESSAGE;
  }
  return undefined;
}

/**
 * Validates the whole form. `verified` is true when a Turnstile token is held. Returns only the fields with a problem.
 * Values are trimmed before checking, the same way they are sent.
 */
export function validateRegister(values: RegisterValues, verified: boolean): RegisterErrors {
  const errors: RegisterErrors = {};
  const first = values.first_name.trim();
  const last = values.last_name.trim();
  const email = values.email.trim();
  const confirmation = values.email_confirmation.trim();
  const street = values.street.trim();
  const street2 = values.street2.trim();
  const city = values.city.trim();
  const state = values.state.trim();
  const zip = values.zip.trim();
  const phone = values.phone.trim();

  if (first === '') {
    errors.first_name = 'Enter your first name.';
  } else {
    errors.first_name = textProblems(first, 'First name', FIELD_LIMITS.first_name);
  }
  if (last === '') {
    errors.last_name = 'Enter your last name.';
  } else {
    errors.last_name = textProblems(last, 'Last name', FIELD_LIMITS.last_name);
  }

  if (email === '') {
    errors.email = 'Enter your email address.';
  } else if (!EMAIL_PATTERN.test(email)) {
    errors.email = 'Enter a valid email address.';
  } else if (email.length > FIELD_LIMITS.email) {
    errors.email = `Email must be ${FIELD_LIMITS.email} characters or fewer.`;
  }

  if (confirmation === '') {
    errors.email_confirmation = 'Confirm your email address.';
  } else if (confirmation !== email) {
    errors.email_confirmation = 'Email confirmation must match your email address.';
  }

  if (street === '') {
    errors.street = 'Enter your street address.';
  } else {
    errors.street = textProblems(street, 'Street address', FIELD_LIMITS.street);
  }
  if (street2 !== '') {
    errors.street2 = textProblems(street2, 'Street address line 2', FIELD_LIMITS.street2);
  }
  if (city === '') {
    errors.city = 'Enter your city.';
  } else {
    errors.city = textProblems(city, 'City', FIELD_LIMITS.city);
  }
  if (state === '') {
    errors.state = 'Enter your state.';
  } else {
    errors.state = textProblems(state, 'State', FIELD_LIMITS.state);
  }
  if (zip === '') {
    errors.zip = 'Enter your ZIP code.';
  } else {
    errors.zip = textProblems(zip, 'ZIP code', FIELD_LIMITS.zip);
  }

  if (phone === '') {
    errors.phone = 'Enter your phone number.';
  } else if (!PHONE_PATTERN.test(phone)) {
    errors.phone = 'Enter a phone number in the format 123-123-1234.';
  }

  if (values.password === '') {
    errors.password = 'Enter a password.';
  } else if (values.password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `Password must be at least ${PASSWORD_MIN_LENGTH} characters long.`;
  }
  if (values.password_confirmation === '') {
    errors.password_confirmation = 'Confirm your password.';
  } else if (values.password_confirmation !== values.password) {
    errors.password_confirmation = 'Password confirmation must match your password.';
  }

  if (!values.accept_privacy_policy) {
    errors.accept_privacy_policy = 'Accept the terms and privacy policy to create an account.';
  }
  if (!verified) {
    errors.cf_turnstile_token = 'Please complete the verification.';
  }

  return Object.fromEntries(Object.entries(errors).filter(([, message]) => message !== undefined)) as RegisterErrors;
}

/** Ordered rules: the first pattern that matches a server message names the field it belongs to. */
const SERVER_MESSAGE_FIELDS: ReadonlyArray<[RegExp, RegisterField]> = [
  [/already|registered|exists|in use/i, 'email'],
  [/email confirm|confirmation.*email/i, 'email_confirmation'],
  [/email/i, 'email'],
  [/password.*confirm|confirm.*password|passwords? (do not|don't) match/i, 'password_confirmation'],
  [/password/i, 'password'],
  [/phone/i, 'phone'],
  [/first.?name/i, 'first_name'],
  [/last.?name/i, 'last_name'],
  [/street.*2|line 2|address 2/i, 'street2'],
  [/street|address/i, 'street'],
  [/\bcity\b/i, 'city'],
  [/\bstate\b/i, 'state'],
  [/zip|postal/i, 'zip'],
  [/privacy|terms|consent|accept/i, 'accept_privacy_policy'],
  [/verification|turnstile|captcha/i, 'cf_turnstile_token'],
];

/**
 * Splits server messages into field errors (when the wording names a field) and banner messages (the rest).
 * Legacy 400 bodies are plain message arrays, so the field has to be read from the text.
 */
export function mapServerMessages(messages: string[]): { fields: RegisterErrors; banner: string[] } {
  const fields: RegisterErrors = {};
  const banner: string[] = [];
  for (const message of messages) {
    const match = SERVER_MESSAGE_FIELDS.find(([pattern]) => pattern.test(message));
    if (match === undefined) {
      banner.push(message);
    } else if (fields[match[1]] === undefined) {
      fields[match[1]] = message;
    } else {
      banner.push(message);
    }
  }
  return { fields, banner };
}
