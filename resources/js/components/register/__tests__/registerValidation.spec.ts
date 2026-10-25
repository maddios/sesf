import { describe, it, expect } from 'vitest';
import {
  FIELD_LIMITS,
  LATIN1_MESSAGE,
  formatPhone,
  isLatin1,
  mapServerMessages,
  validateRegister,
  type RegisterValues,
} from '../registerValidation';

const VALID: RegisterValues = {
  first_name: 'Shark',
  last_name: 'Fan',
  email: 'fan@example.test',
  email_confirmation: 'fan@example.test',
  street: '1 Ocean Way',
  street2: '',
  city: 'Charlotte',
  state: 'NC',
  zip: '28202',
  phone: '123-123-1234',
  password: 'secret1',
  password_confirmation: 'secret1',
  accept_privacy_policy: true,
};

function withValues(patch: Partial<RegisterValues>): RegisterValues {
  return { ...VALID, ...patch };
}

describe('validateRegister', () => {
  it('accepts a complete, verified form', () => {
    expect(validateRegister(VALID, true)).toEqual({});
  });

  it('requires the Turnstile token', () => {
    expect(validateRegister(VALID, false)).toEqual({ cf_turnstile_token: 'Please complete the verification.' });
  });

  it('requires the privacy consent checkbox', () => {
    expect(validateRegister(withValues({ accept_privacy_policy: false }), true)).toEqual({
      accept_privacy_policy: 'Accept the terms and privacy policy to create an account.',
    });
  });

  it('reports required text fields as empty (after trimming)', () => {
    const errors = validateRegister(
      withValues({ first_name: '   ', last_name: '', street: ' ', city: '', state: '', zip: '' }),
      true,
    );
    expect(errors).toMatchObject({
      first_name: 'Enter your first name.',
      last_name: 'Enter your last name.',
      street: 'Enter your street address.',
      city: 'Enter your city.',
      state: 'Enter your state.',
      zip: 'Enter your ZIP code.',
    });
  });

  it('does not require street line 2', () => {
    expect(validateRegister(withValues({ street2: '' }), true)).toEqual({});
  });

  it('enforces the width limits on names, address and state/zip', () => {
    const errors = validateRegister(
      withValues({
        first_name: 'a'.repeat(FIELD_LIMITS.first_name + 1),
        last_name: 'b'.repeat(FIELD_LIMITS.last_name + 1),
        street: 'c'.repeat(FIELD_LIMITS.street + 1),
        street2: 'd'.repeat(FIELD_LIMITS.street2 + 1),
        city: 'e'.repeat(FIELD_LIMITS.city + 1),
        state: 'NCAAAAAAAAAA',
        zip: '123456789012',
      }),
      true,
    );
    expect(errors.first_name).toBe('First name must be 20 characters or fewer.');
    expect(errors.last_name).toBe('Last name must be 20 characters or fewer.');
    expect(errors.street).toBe('Street address must be 60 characters or fewer.');
    expect(errors.street2).toBe('Street address line 2 must be 60 characters or fewer.');
    expect(errors.city).toBe('City must be 60 characters or fewer.');
    expect(errors.state).toBe('State must be 10 characters or fewer.');
    expect(errors.zip).toBe('ZIP code must be 10 characters or fewer.');
  });

  it('accepts values exactly at the limits', () => {
    expect(
      validateRegister(
        withValues({ first_name: 'a'.repeat(20), last_name: 'b'.repeat(20), street: 'c'.repeat(60), state: 'NCXXXXXXXX', zip: '1234567890' }),
        true,
      ),
    ).toEqual({});
  });

  it('rejects emails that are malformed, empty or too long', () => {
    expect(validateRegister(withValues({ email: '', email_confirmation: '' }), true).email).toBe('Enter your email address.');
    expect(validateRegister(withValues({ email: 'not-an-email', email_confirmation: 'not-an-email' }), true).email).toBe(
      'Enter a valid email address.',
    );
    const long = `${'a'.repeat(55)}@x.test`;
    expect(validateRegister(withValues({ email: long, email_confirmation: long }), true).email).toBe(
      'Email must be 60 characters or fewer.',
    );
  });

  it('requires the email confirmation and makes it match the email', () => {
    expect(validateRegister(withValues({ email_confirmation: '' }), true).email_confirmation).toBe('Confirm your email address.');
    expect(validateRegister(withValues({ email_confirmation: 'other@example.test' }), true).email_confirmation).toBe(
      'Email confirmation must match your email address.',
    );
  });

  it('checks the phone format ###-###-####', () => {
    expect(validateRegister(withValues({ phone: '' }), true).phone).toBe('Enter your phone number.');
    for (const bad of ['1231231234', '123-1234567', '(123) 123-1234', '123-123-12345']) {
      expect(validateRegister(withValues({ phone: bad }), true).phone).toBe('Enter a phone number in the format 123-123-1234.');
    }
  });

  it('requires a password of at least six characters and a matching confirmation', () => {
    expect(validateRegister(withValues({ password: '', password_confirmation: '' }), true).password).toBe('Enter a password.');
    expect(validateRegister(withValues({ password: '12345', password_confirmation: '12345' }), true).password).toBe(
      'Password must be at least 6 characters long.',
    );
    expect(validateRegister(withValues({ password_confirmation: '' }), true).password_confirmation).toBe('Confirm your password.');
    expect(validateRegister(withValues({ password_confirmation: 'secret2' }), true).password_confirmation).toBe(
      'Password confirmation must match your password.',
    );
  });

  it('rejects characters outside Latin-1 with the legacy wording', () => {
    expect(validateRegister(withValues({ first_name: 'Sha🦈rk' }), true).first_name).toBe(LATIN1_MESSAGE);
    expect(validateRegister(withValues({ city: 'Zürich' }), true).city).toBeUndefined();
  });
});

describe('isLatin1', () => {
  it('accepts Latin-1 letters and rejects emoji and CJK', () => {
    expect(isLatin1('José Ñandú ÿ')).toBe(true);
    expect(isLatin1('ok 🦈')).toBe(false);
    expect(isLatin1('漢字')).toBe(false);
  });
});

describe('formatPhone', () => {
  it('formats digits as they are typed', () => {
    expect(formatPhone('')).toBe('');
    expect(formatPhone('12')).toBe('12');
    expect(formatPhone('123')).toBe('123');
    expect(formatPhone('1234')).toBe('123-4');
    expect(formatPhone('123456')).toBe('123-456');
    expect(formatPhone('1234567')).toBe('123-456-7');
    expect(formatPhone('1231231234')).toBe('123-123-1234');
  });

  it('strips punctuation, pasted text and digits beyond ten', () => {
    expect(formatPhone('(123) 123-1234')).toBe('123-123-1234');
    expect(formatPhone('+1 123 123 1234 55')).toBe('112-312-3123');
    expect(formatPhone('abc')).toBe('');
  });
});

describe('mapServerMessages', () => {
  it('maps duplicate-account wording to the email field', () => {
    expect(mapServerMessages(['That email address is already registered.'])).toEqual({
      fields: { email: 'That email address is already registered.' },
      banner: [],
    });
  });

  it('maps field-named messages to their fields', () => {
    const result = mapServerMessages([
      'The phone number is invalid.',
      'The password confirmation does not match.',
      'The first name is required.',
      'The last name is required.',
      'The zip code is not valid.',
      'The city is required.',
      'The state is required.',
      'The street address is required.',
      'The street address line 2 is too long.',
      'You must accept the privacy policy.',
      'Verification failed. Try again.',
      'The email confirmation does not match.',
    ]);
    expect(result.fields).toEqual({
      phone: 'The phone number is invalid.',
      password_confirmation: 'The password confirmation does not match.',
      first_name: 'The first name is required.',
      last_name: 'The last name is required.',
      zip: 'The zip code is not valid.',
      city: 'The city is required.',
      state: 'The state is required.',
      street: 'The street address is required.',
      street2: 'The street address line 2 is too long.',
      accept_privacy_policy: 'You must accept the privacy policy.',
      cf_turnstile_token: 'Verification failed. Try again.',
      email_confirmation: 'The email confirmation does not match.',
    });
    expect(result.banner).toEqual([]);
  });

  it('maps password wording and address wording that is not street or line 2', () => {
    expect(mapServerMessages(['Password must be at least 6 characters long.']).fields).toEqual({
      password: 'Password must be at least 6 characters long.',
    });
    expect(mapServerMessages(['Address is missing.']).fields).toEqual({ street: 'Address is missing.' });
  });

  it('keeps messages it cannot place in the banner, and a second message for an already-used field too', () => {
    const result = mapServerMessages(['Something unexpected happened.', 'Email is invalid.', 'Email is blocked.']);
    expect(result.fields).toEqual({ email: 'Email is invalid.' });
    expect(result.banner).toEqual(['Something unexpected happened.', 'Email is blocked.']);
  });
});
