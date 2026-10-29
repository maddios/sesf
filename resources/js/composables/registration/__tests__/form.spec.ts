import { describe, it, expect } from 'vitest';
import { buildRequest, cloneForm, emptyForm, enabledExtras, extraBounds, formFromRegistration, freeGuestSlots, isAllowedDonation, restoreForm, nextRowKey } from '../form';
import { toRegistrationData } from '../../../api/registration';
import { registrationRaw } from '../../../pages/registration/__tests__/registrationFixtures';

const data = () => toRegistrationData(registrationRaw());

describe('formFromRegistration', () => {
  it('copies the saved selections and starts unsaved extras at zero', () => {
    const form = formFromRegistration(data());
    expect(form.cars).toEqual([{ id: 3, judgingType: 21 }]);
    expect(form.guests).toHaveLength(1);
    expect(form.guests[0]).toMatchObject({ id: 5, first: 'Pat', last: 'Guest', type: 2, deleted: false });
    expect(form.shirts[0]).toMatchObject({ id: 8, type: 1 });
    expect(form.extras).toEqual({ '4': 1, '5': 0, '6': 0 });
    expect(form.arrivalDate).toBe('2026-04-30');
    expect(form.arrivalTime).toBe('morning');
    expect(form.donation).toBe(10);
    expect(form.cancelAck).toBe(false);
  });
});

describe('buildRequest', () => {
  it('builds the contract body with snake_case keys and only the set optional fields', () => {
    const form = formFromRegistration(data());
    form.guests.push({ key: nextRowKey(), id: null, first: ' Ann ', last: 'Lee', email: ' ann@example.test ', type: 1, deleted: false });
    form.guests.push({ key: nextRowKey(), id: null, first: 'Old', last: 'One', email: '', type: 1, deleted: true });
    const body = buildRequest(form, true).registration;
    expect(body.cars).toEqual([{ id: 3, judging_type: 21 }]);
    expect(body.guests[0]).toEqual({ id: 5, first: 'Pat', last: 'Guest', type: 2 });
    expect(body.guests[1]).toEqual({ first: 'Ann', last: 'Lee', email: 'ann@example.test', type: 1 });
    expect(body.guests[2]).toEqual({ first: 'Old', last: 'One', type: 1, deleted: true });
    expect(body.shirts).toEqual([{ id: 8, type: 1 }]);
    expect(body.extras).toEqual({
      '4': { show_extra_id: 4, num_extras: 1 },
      '5': { show_extra_id: 5, num_extras: 0 },
      '6': { show_extra_id: 6, num_extras: 0 },
    });
    expect(body.arrival_date).toEqual({ data: '2026-04-30' });
    expect(body.arrival_time).toBe('morning');
    expect(body.donation).toBe(10);
    expect(body.cancel).toBe(true);
  });

  it('omits cancel unless it is given', () => {
    expect(buildRequest(emptyForm()).registration).not.toHaveProperty('cancel');
    expect(buildRequest(emptyForm(), false).registration.cancel).toBe(false);
  });

  it('marks removed saved shirts as deleted and keeps the ids', () => {
    const form = formFromRegistration(data());
    form.shirts[0]!.deleted = true;
    expect(buildRequest(form).registration.shirts).toEqual([{ id: 8, type: 1, deleted: true }]);
  });
});

describe('restoreForm and cloneForm', () => {
  it('drops cars that are no longer offered and reissues row keys', () => {
    const form = formFromRegistration(data());
    form.cars.push({ id: 99, judgingType: null });
    const oldKey = form.guests[0]!.key;
    const restored = restoreForm(form, [3, 4]);
    expect(restored.cars).toEqual([{ id: 3, judgingType: 21 }]);
    expect(restored.guests[0]!.key).not.toBe(oldKey);
  });

  it('clones without sharing arrays', () => {
    const form = formFromRegistration(data());
    const copy = cloneForm(form);
    copy.cars.push({ id: 4, judgingType: null });
    expect(form.cars).toHaveLength(1);
  });
});

describe('small helpers', () => {
  it('free guest slots: none for the first car, one per additional car', () => {
    expect(freeGuestSlots(0)).toBe(0);
    expect(freeGuestSlots(1)).toBe(0);
    expect(freeGuestSlots(3)).toBe(2);
  });

  it('enabled extras and bounds', () => {
    const extras = data().extras;
    expect(enabledExtras(extras).map((extra) => extra.id)).toEqual([4, 5]);
    expect(extraBounds(extras[1]!)).toEqual({ min: 0, max: 1, checkbox: true });
    expect(extraBounds(extras[0]!)).toEqual({ min: 0, max: 3, checkbox: false });
  });

  it('accepts only donations on the allow-list', () => {
    expect(isAllowedDonation(0)).toBe(true);
    expect(isAllowedDonation(500)).toBe(true);
    expect(isAllowedDonation(7)).toBe(false);
  });
});
