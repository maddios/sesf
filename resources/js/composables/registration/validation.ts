import { isAllowedDonation, extraBounds, freeGuestSlots, type FormValues } from './form';
import { ARRIVAL_TIMES, type RegistrableCar, type ShowExtra } from '../../api/registration';

/** Guest name rule: starts with a letter or digit, then letters, digits, spaces, dots, apostrophes or hyphens. */
export const NAME_MAX_LENGTH = 24;
const NAME_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N} .'-]*$/u;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export interface ValidationContext {
  /** The signed-in member's email: a guest email must differ from it. */
  memberEmail: string;
  cars: RegistrableCar[];
  extras: ShowExtra[];
  /** Event dates (YYYY-MM-DD) an arrival may use. */
  eventDates: string[];
}

/**
 * Client-side rules, keyed by field path (for example "guests.2.first"). The server stays the authority: its
 * messages are mapped onto sections separately.
 */
export type FieldErrors = Record<string, string>;

export function validateName(value: string, label: string): string | null {
  const trimmed = value.trim();
  if (trimmed === '') {
    return `Enter the ${label} name.`;
  }
  if (trimmed.length > NAME_MAX_LENGTH) {
    return `The ${label} name must be ${NAME_MAX_LENGTH} characters or fewer.`;
  }
  if (!NAME_PATTERN.test(trimmed)) {
    return `The ${label} name may only use letters, numbers, spaces, periods, apostrophes and hyphens.`;
  }
  return null;
}

export function validateGuestEmail(value: string, memberEmail: string): string | null {
  const trimmed = value.trim();
  if (trimmed === '') {
    return null;
  }
  if (!EMAIL_PATTERN.test(trimmed)) {
    return 'Enter a valid email address.';
  }
  if (memberEmail !== '' && trimmed.toLowerCase() === memberEmail.trim().toLowerCase()) {
    return 'The guest email must be different from your own. It is used for the guest waiver.';
  }
  return null;
}

export function validateRegistration(values: FormValues, context: ValidationContext): FieldErrors {
  const errors: FieldErrors = {};
  const carsById = new Map(context.cars.map((car) => [car.id, car]));

  for (const choice of values.cars) {
    const car = carsById.get(choice.id);
    if (car !== undefined && car.judgingTypes.length > 0 && choice.judgingType === null) {
      errors[`cars.${choice.id}.judgingType`] = 'Choose a judging type for this car.';
    }
  }

  values.guests.forEach((guest, index) => {
    if (guest.deleted) {
      return;
    }
    const first = validateName(guest.first, 'first');
    if (first !== null) {
      errors[`guests.${index}.first`] = first;
    }
    const last = validateName(guest.last, 'last');
    if (last !== null) {
      errors[`guests.${index}.last`] = last;
    }
    const email = validateGuestEmail(guest.email, context.memberEmail);
    if (email !== null) {
      errors[`guests.${index}.email`] = email;
    }
    if (guest.type === null) {
      errors[`guests.${index}.type`] = 'Choose a guest type.';
    }
  });

  values.shirts.forEach((shirt, index) => {
    if (!shirt.deleted && shirt.type === null) {
      errors[`shirts.${index}.type`] = 'Choose a shirt size.';
    }
  });

  for (const extra of context.extras) {
    const quantity = values.extras[String(extra.id)] ?? 0;
    const bounds = extraBounds(extra);
    const max = bounds.max ?? Number.POSITIVE_INFINITY;
    // 0 means "not chosen" and is always allowed (the server removes the row), whatever min_value says.
    if (!Number.isInteger(quantity) || (quantity !== 0 && (quantity < bounds.min || quantity > max))) {
      errors[`extras.${extra.id}`] =
        bounds.max === null
          ? `Choose at least ${bounds.min}.`
          : `Choose a number from ${bounds.min} to ${bounds.max}.`;
    }
  }

  if (!context.eventDates.includes(values.arrivalDate)) {
    errors['arrival.date'] = 'Choose the day you expect to arrive.';
  }
  if (!ARRIVAL_TIMES.some((time) => time.value === values.arrivalTime)) {
    errors['arrival.time'] = 'Choose the time you expect to arrive.';
  }
  if (!isAllowedDonation(values.donation)) {
    errors.donation = 'Choose a donation from the list.';
  }
  return errors;
}

/** The policy acknowledgement is checked on submit only. */
export function validateCancelAck(values: FormValues): FieldErrors {
  return values.cancelAck ? {} : { cancel: 'Confirm that you have read the registration and cancellation policy.' };
}

/** Explains the free-guest rule for the guests section. */
export function guestHint(carCount: number): string {
  if (carCount === 0) {
    return 'Your first car does not include a guest. Each additional car includes one free guest.';
  }
  const free = freeGuestSlots(carCount);
  const noun = free === 1 ? 'free guest' : 'free guests';
  if (carCount === 1) {
    return 'Your first car does not include a free guest. Each additional car includes one free guest.';
  }
  return `${carCount} cars include ${free} ${noun}. Each additional car includes one more.`;
}
