import { describe, it, expect } from 'vitest';
import { emptyForm, formFromRegistration, nextRowKey, type FormValues } from '../form';
import { guestHint, validateCancelAck, validateGuestEmail, validateName, validateRegistration, NAME_MAX_LENGTH } from '../validation';
import { toRegistrationData } from '../../../api/registration';
import { registrationRaw } from '../../../pages/registration/__tests__/registrationFixtures';

const data = toRegistrationData(registrationRaw());
const context = {
  memberEmail: 'fan@example.test',
  cars: data.cars,
  extras: data.extras.filter((extra) => extra.isEnabled),
  eventDates: ['2026-04-30', '2026-05-01'],
};

function validForm(): FormValues {
  const form = formFromRegistration(data);
  form.cancelAck = true;
  return form;
}

describe('validateName', () => {
  it('requires a value, a length limit and the allowed characters', () => {
    expect(validateName('  ', 'first')).toMatch(/Enter the first name/);
    expect(validateName('a'.repeat(NAME_MAX_LENGTH + 1), 'last')).toMatch(/24 characters/);
    expect(validateName('O\'Brien-Smith Jr.', 'last')).toBeNull();
    expect(validateName('<b>', 'first')).toMatch(/may only use/);
    expect(validateName('-leading', 'first')).toMatch(/may only use/);
    expect(validateName('Zoë', 'first')).toBeNull();
  });
});

describe('validateGuestEmail', () => {
  it('is optional, must be well formed and must differ from the member', () => {
    expect(validateGuestEmail('', 'fan@example.test')).toBeNull();
    expect(validateGuestEmail('nope', 'fan@example.test')).toMatch(/valid email/);
    expect(validateGuestEmail('FAN@example.test', 'fan@example.test')).toMatch(/different from your own/);
    expect(validateGuestEmail('guest@example.test', '')).toBeNull();
  });
});

describe('validateRegistration', () => {
  it('passes a complete form', () => {
    expect(validateRegistration(validForm(), context)).toEqual({});
  });

  it('asks for a judging type only on cars that have judging types', () => {
    const form = validForm();
    form.cars = [
      { id: 3, judgingType: null },
      { id: 4, judgingType: null },
    ];
    expect(validateRegistration(form, context)).toEqual({ 'cars.3.judgingType': 'Choose a judging type for this car.' });
  });

  it('checks each guest field and skips removed guests', () => {
    const form = validForm();
    form.guests = [
      { key: nextRowKey(), id: null, first: '', last: 'Ok', email: 'bad', type: null, deleted: false },
      { key: nextRowKey(), id: null, first: '', last: '', email: '', type: null, deleted: true },
    ];
    const errors = validateRegistration(form, context);
    expect(Object.keys(errors).sort()).toEqual(['guests.0.email', 'guests.0.first', 'guests.0.type']);
  });

  it('requires a shirt size on each shirt that is kept', () => {
    const form = validForm();
    form.shirts = [
      { key: nextRowKey(), id: null, type: null, deleted: false },
      { key: nextRowKey(), id: null, type: null, deleted: true },
    ];
    expect(validateRegistration(form, context)).toEqual({ 'shirts.0.type': 'Choose a shirt size.' });
  });

  it('allows 0 (not chosen) for an extra whose minimum is 1, like the server', () => {
    const form = formFromRegistration(data);
    const min1 = { ...context, extras: [{ ...context.extras[0]!, minValue: 1, maxValue: 1 }] };
    form.extras['4'] = 0;
    expect(validateRegistration(form, min1)['extras.4']).toBeUndefined();
    form.extras['4'] = 2;
    expect(validateRegistration(form, min1)['extras.4']).toBe('Choose a number from 1 to 1.');
  });

  it('keeps extra quantities inside their bounds', () => {
    const form = validForm();
    form.extras['4'] = 4;
    form.extras['5'] = 2;
    const errors = validateRegistration(form, context);
    expect(errors['extras.4']).toBe('Choose a number from 0 to 3.');
    expect(errors['extras.5']).toBe('Choose a number from 0 to 1.');
  });

  it('describes an open-ended maximum as a minimum', () => {
    const form = validForm();
    form.extras['4'] = -1;
    const open = { ...context, extras: [{ ...context.extras[0]!, maxValue: null }] };
    expect(validateRegistration(form, open)['extras.4']).toBe('Choose at least 0.');
  });

  it('requires an arrival day from the event dates and a time', () => {
    const form = validForm();
    form.arrivalDate = '2030-01-01';
    form.arrivalTime = 'noon';
    const errors = validateRegistration(form, context);
    expect(errors['arrival.date']).toMatch(/day/);
    expect(errors['arrival.time']).toMatch(/time/);
  });

  it('rejects a donation outside the allow-list', () => {
    const form = validForm();
    form.donation = 7;
    expect(validateRegistration(form, context).donation).toMatch(/donation/);
  });

  it('handles an empty form', () => {
    expect(Object.keys(validateRegistration(emptyForm(), context))).toEqual(['arrival.date', 'arrival.time']);
  });
});

describe('validateCancelAck and guestHint', () => {
  it('requires the policy acknowledgement', () => {
    expect(validateCancelAck(emptyForm()).cancel).toMatch(/Confirm/);
    expect(validateCancelAck({ ...emptyForm(), cancelAck: true })).toEqual({});
  });

  it('explains the free guest rule by car count', () => {
    expect(guestHint(0)).toMatch(/first car does not include a guest/);
    expect(guestHint(1)).toMatch(/does not include a free guest/);
    expect(guestHint(2)).toBe('2 cars include 1 free guest. Each additional car includes one more.');
    expect(guestHint(3)).toBe('3 cars include 2 free guests. Each additional car includes one more.');
  });
});
