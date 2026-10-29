import { DONATION_OPTIONS, type Registration, type RegistrationData, type RegistrationRequest, type ShowExtra } from '../../api/registration';

/**
 * Editable registration state. Rows keep a local `key` for v-for; `deleted` rows stay in the array so their index
 * still matches the server's per-row error messages (existing rows are sent back with deleted: true).
 */
export interface CarChoice {
  id: number;
  judgingType: number | null;
}

export interface GuestRow {
  key: number;
  id: number | null;
  first: string;
  last: string;
  email: string;
  type: number | null;
  deleted: boolean;
}

export interface ShirtRow {
  key: number;
  id: number | null;
  type: number | null;
  deleted: boolean;
}

export interface FormValues {
  cars: CarChoice[];
  guests: GuestRow[];
  shirts: ShirtRow[];
  /** show_extra_id -> quantity. */
  extras: Record<string, number>;
  arrivalDate: string;
  arrivalTime: string;
  donation: number;
  /** Policy acknowledgement. Client-only: it is required to submit and never sent as a cancellation. */
  cancelAck: boolean;
}

let keyCounter = 0;

export function nextRowKey(): number {
  keyCounter += 1;
  return keyCounter;
}

export function emptyForm(): FormValues {
  return {
    cars: [],
    guests: [],
    shirts: [],
    extras: {},
    arrivalDate: '',
    arrivalTime: '',
    donation: 0,
    cancelAck: false,
  };
}

/** Form values from the saved registration. Extras not yet saved start at 0. */
export function formFromRegistration(data: RegistrationData): FormValues {
  const reg: Registration = data.registration;
  const extras: Record<string, number> = {};
  for (const extra of data.extras) {
    extras[String(extra.id)] = 0;
  }
  for (const saved of reg.extras) {
    extras[String(saved.showExtraId)] = saved.numExtras;
  }
  return {
    cars: reg.cars.map((car) => ({ id: car.id, judgingType: car.judgingType })),
    guests: reg.guests.map((guest) => ({
      key: nextRowKey(),
      id: guest.id,
      first: guest.first,
      last: guest.last,
      email: guest.email,
      type: guest.type,
      deleted: false,
    })),
    shirts: reg.shirts.map((shirt) => ({ key: nextRowKey(), id: shirt.id, type: shirt.type, deleted: false })),
    extras,
    arrivalDate: reg.arrivalDate,
    arrivalTime: reg.arrivalTime,
    donation: reg.donation,
    cancelAck: false,
  };
}

/** Copy of the form that is safe to store as JSON (no proxies, fresh row keys on restore). */
export function cloneForm(values: FormValues): FormValues {
  return JSON.parse(JSON.stringify(values)) as FormValues;
}

/** Restores a stored draft: unknown cars are dropped and row keys are reissued. */
export function restoreForm(stored: FormValues, carIds: number[]): FormValues {
  const copy = cloneForm(stored);
  return {
    ...copy,
    cars: copy.cars.filter((car) => carIds.includes(car.id)),
    guests: copy.guests.map((guest) => ({ ...guest, key: nextRowKey() })),
    shirts: copy.shirts.map((shirt) => ({ ...shirt, key: nextRowKey() })),
  };
}

/** Free guest slots: the first car gives none, each additional car gives one. */
export function freeGuestSlots(carCount: number): number {
  return Math.max(0, carCount - 1);
}

export function visibleGuests(values: FormValues): GuestRow[] {
  return values.guests.filter((guest) => !guest.deleted);
}

export function visibleShirts(values: FormValues): ShirtRow[] {
  return values.shirts.filter((shirt) => !shirt.deleted);
}

/** Enabled extras only: the form has no fields for hidden ones. */
export function enabledExtras(extras: ShowExtra[]): ShowExtra[] {
  return extras.filter((extra) => extra.isEnabled);
}

/**
 * Builds the POST and quote body. `cancel` is sent only when given (admin cancel/reinstate); the member form omits it.
 */
export function buildRequest(values: FormValues, cancel?: boolean): RegistrationRequest {
  const extras: RegistrationRequest['registration']['extras'] = {};
  for (const [id, num] of Object.entries(values.extras)) {
    extras[id] = { show_extra_id: Number(id), num_extras: num };
  }
  return {
    registration: {
      cars: values.cars.map((car) => ({ id: car.id, judging_type: car.judgingType })),
      guests: values.guests.map((guest) => {
        const item: RegistrationRequest['registration']['guests'][number] = {
          first: guest.first.trim(),
          last: guest.last.trim(),
          type: guest.type,
        };
        if (guest.id !== null) {
          item.id = guest.id;
        }
        if (guest.email.trim() !== '') {
          item.email = guest.email.trim();
        }
        if (guest.deleted) {
          item.deleted = true;
        }
        return item;
      }),
      shirts: values.shirts.map((shirt) => {
        const item: RegistrationRequest['registration']['shirts'][number] = { type: shirt.type };
        if (shirt.id !== null) {
          item.id = shirt.id;
        }
        if (shirt.deleted) {
          item.deleted = true;
        }
        return item;
      }),
      extras,
      arrival_date: { data: values.arrivalDate },
      arrival_time: values.arrivalTime,
      donation: values.donation,
      ...(cancel === undefined ? {} : { cancel }),
    },
  };
}

export function isAllowedDonation(value: number): boolean {
  return DONATION_OPTIONS.includes(value);
}

/** Quantity bounds for one extra. A max of 1 renders as a checkbox. */
export function extraBounds(extra: ShowExtra): { min: number; max: number | null; checkbox: boolean } {
  return { min: extra.minValue, max: extra.maxValue, checkbox: extra.maxValue === 1 };
}
