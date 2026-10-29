import type { AccountInfoInput, AddressInfo, CarInput, PasswordInput } from '../../api/account';

/**
 * Client-side checks that mirror the server limits. Each validator returns field -> message; an empty object
 * means the form may be sent. The server stays the authority and its 400 messages are still shown.
 */
export type FieldErrors<K extends string> = Partial<Record<K, string>>;

export const LIMITS = {
  name: 20,
  email: 60,
  address: 60,
  zip: 10,
  password: 6,
  nickname: { min: 2, max: 16 },
  vin: { min: 5, max: 7 },
  model: 30,
  year: 5,
} as const;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const VIN_PATTERN = /^[A-Za-z0-9]+$/;
const STATE_PATTERN = /^[A-Za-z]{2}$/;

export type AccountInfoField = keyof AccountInfoInput;

/** The current password is required only when the email differs from the stored one. */
export function validateAccountInfo(input: AccountInfoInput, savedEmail: string): FieldErrors<AccountInfoField> {
  const errors: FieldErrors<AccountInfoField> = {};
  if (input.first.trim() === '') {
    errors.first = 'Enter your first name.';
  } else if (input.first.trim().length > LIMITS.name) {
    errors.first = `First name must be ${LIMITS.name} characters or fewer.`;
  }
  if (input.last.trim() === '') {
    errors.last = 'Enter your last name.';
  } else if (input.last.trim().length > LIMITS.name) {
    errors.last = `Last name must be ${LIMITS.name} characters or fewer.`;
  }
  const email = input.email.trim();
  if (email === '') {
    errors.email = 'Enter your email address.';
  } else if (email.length > LIMITS.email || !EMAIL_PATTERN.test(email)) {
    errors.email = `Enter a valid email address of ${LIMITS.email} characters or fewer.`;
  }
  if (email !== savedEmail.trim() && (input.currentPassword ?? '') === '') {
    errors.currentPassword = 'Enter your current password to change your email address.';
  }
  return errors;
}

export function validatePassword(input: PasswordInput): FieldErrors<keyof PasswordInput> {
  const errors: FieldErrors<keyof PasswordInput> = {};
  if (input.oldPassword === '') {
    errors.oldPassword = 'Enter your current password.';
  }
  if (input.newPassword.length < LIMITS.password) {
    errors.newPassword = `Use at least ${LIMITS.password} characters.`;
  }
  if (input.verifyPassword !== input.newPassword) {
    errors.verifyPassword = 'The passwords do not match.';
  }
  return errors;
}

export function validateAddress(input: AddressInfo): FieldErrors<keyof AddressInfo> {
  const errors: FieldErrors<keyof AddressInfo> = {};
  for (const key of ['address1', 'address2', 'city'] as const) {
    if (input[key].length > LIMITS.address) {
      errors[key] = `Use ${LIMITS.address} characters or fewer.`;
    }
  }
  if (input.state !== '' && !STATE_PATTERN.test(input.state)) {
    errors.state = 'Use the two-letter state code, for example NY.';
  }
  if (input.zip.length > LIMITS.zip) {
    errors.zip = `Use ${LIMITS.zip} characters or fewer.`;
  }
  return errors;
}

export type CarField = 'nickname' | 'model' | 'year' | 'vin' | 'bodyId' | 'typeId';

/** Locked cars (registered for a show) skip the locked fields: the server ignores them. */
export function validateCar(input: CarInput, locked: boolean): FieldErrors<CarField> {
  const errors: FieldErrors<CarField> = {};
  const nickname = input.nickname.trim();
  if (nickname.length < LIMITS.nickname.min || nickname.length > LIMITS.nickname.max) {
    errors.nickname = `Nickname must be ${LIMITS.nickname.min} to ${LIMITS.nickname.max} characters.`;
  }
  if (!locked) {
    if (input.bodyId <= 0) {
      errors.bodyId = 'Choose a body style.';
    }
    if (input.typeId <= 0) {
      errors.typeId = 'Choose a car type.';
    }
    if (input.model.length > LIMITS.model) {
      errors.model = `Model must be ${LIMITS.model} characters or fewer.`;
    }
    if (input.year.length > LIMITS.year) {
      errors.year = `Year must be ${LIMITS.year} characters or fewer.`;
    }
    const vin = input.vin.trim();
    if (vin !== '' && (vin.length < LIMITS.vin.min || vin.length > LIMITS.vin.max || !VIN_PATTERN.test(vin))) {
      errors.vin = `VIN must be ${LIMITS.vin.min} to ${LIMITS.vin.max} letters or digits.`;
    }
  }
  return errors;
}

export function hasErrors(errors: object): boolean {
  return Object.keys(errors).length > 0;
}
