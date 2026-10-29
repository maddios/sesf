import { describe, it, expect } from 'vitest';
import {
  hasErrors,
  validateAccountInfo,
  validateAddress,
  validateCar,
  validatePassword,
} from '../validation';

const base = { first: 'Shark', last: 'Fan', email: 'fan@example.test', phone: '', currentPassword: '' };

describe('validateAccountInfo', () => {
  it('accepts a complete form with an unchanged email', () => {
    expect(validateAccountInfo(base, 'fan@example.test')).toEqual({});
  });

  it('requires names and a valid email, and enforces the 20 and 60 character limits', () => {
    const errors = validateAccountInfo({ ...base, first: ' ', last: 'x'.repeat(21), email: 'nope' }, 'fan@example.test');
    expect(errors.first).toMatch(/first name/i);
    expect(errors.last).toMatch(/20/);
    expect(errors.email).toMatch(/valid email/);
    expect(validateAccountInfo({ ...base, email: '' }, 'a@b.test').email).toMatch(/Enter your email/);
    expect(validateAccountInfo({ ...base, email: `${'a'.repeat(55)}@b.test` }, 'x@y.test').email).toMatch(/60/);
  });

  it('requires the current password only when the email changes', () => {
    const changed = validateAccountInfo({ ...base, email: 'new@example.test' }, 'fan@example.test');
    expect(changed.currentPassword).toMatch(/current password/);
    expect(validateAccountInfo({ ...base, email: 'new@example.test', currentPassword: 'pw' }, 'fan@example.test')).toEqual({});
    expect(validateAccountInfo({ ...base, first: '', last: '' }, 'fan@example.test')).toMatchObject({ first: expect.any(String), last: expect.any(String) });
    expect(validateAccountInfo({ ...base, last: 'x'.repeat(21) }, 'fan@example.test').last).toMatch(/20/);
  });
});

describe('validatePassword', () => {
  it('needs a current password, six characters and a matching repeat', () => {
    expect(validatePassword({ oldPassword: '', newPassword: 'abc', verifyPassword: 'abd' })).toEqual({
      oldPassword: expect.any(String),
      newPassword: expect.stringMatching(/6/),
      verifyPassword: expect.stringMatching(/match/),
    });
    expect(validatePassword({ oldPassword: 'old', newPassword: 'longer', verifyPassword: 'longer' })).toEqual({});
  });
});

describe('validateAddress', () => {
  it('checks the two-letter state, zip length and 60 character fields', () => {
    expect(validateAddress({ address1: '1 Way', address2: '', city: 'Ames', state: 'IA', zip: '50010' })).toEqual({});
    const bad = validateAddress({ address1: 'x'.repeat(61), address2: 'y'.repeat(61), city: 'z'.repeat(61), state: 'Iowa', zip: '1'.repeat(11) });
    expect(Object.keys(bad).sort()).toEqual(['address1', 'address2', 'city', 'state', 'zip']);
    expect(validateAddress({ address1: '', address2: '', city: '', state: '', zip: '' })).toEqual({});
  });
});

describe('validateCar', () => {
  const car = { nickname: 'Red Bug', model: 'Bug', year: '1971', vin: 'ABC12', bodyId: 2, typeId: 4 };

  it('accepts a valid car', () => {
    expect(validateCar(car, false)).toEqual({});
  });

  it('enforces nickname length and the body and type choices', () => {
    const errors = validateCar({ ...car, nickname: 'a', bodyId: 0, typeId: 0 }, false);
    expect(errors.nickname).toMatch(/2 to 16/);
    expect(errors.bodyId).toMatch(/body/);
    expect(errors.typeId).toMatch(/type/);
    expect(validateCar({ ...car, nickname: 'x'.repeat(17) }, false).nickname).toMatch(/16/);
  });

  it('checks model, year and optional VIN format', () => {
    expect(validateCar({ ...car, model: 'm'.repeat(31) }, false).model).toMatch(/30/);
    expect(validateCar({ ...car, year: '123456' }, false).year).toMatch(/5/);
    expect(validateCar({ ...car, vin: 'AB' }, false).vin).toMatch(/5 to 7/);
    expect(validateCar({ ...car, vin: 'ABCDEFGH' }, false).vin).toMatch(/5 to 7/);
    expect(validateCar({ ...car, vin: 'AB-CD1' }, false).vin).toMatch(/letters or digits/);
    expect(validateCar({ ...car, vin: '' }, false).vin).toBeUndefined();
  });

  it('skips the locked fields when the car is registered for a show', () => {
    const errors = validateCar({ ...car, bodyId: 0, typeId: 0, model: 'm'.repeat(40), vin: 'x' }, true);
    expect(hasErrors(errors)).toBe(false);
    expect(validateCar({ ...car, nickname: '' }, true).nickname).toBeDefined();
  });
});
