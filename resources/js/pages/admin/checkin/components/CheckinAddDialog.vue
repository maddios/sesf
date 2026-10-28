<script setup lang="ts">
/**
 * "New registration" at the check-in table (legacy checkin_list add modal): a member with a car who is not registered
 * yet, their garage cars, arrival, donation, guests, shirts and extras. Create sends the waiver reminder and billing
 * link emails, as legacy did.
 */
import { computed, onMounted, ref } from 'vue';
import BaseButton from '../../../../components/ui/BaseButton.vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import { useToast } from '../../../../composables/admin/useToast';
import {
  checkinApi,
  failureMessage,
  formatCents,
  quantityRange,
  type CheckinRow,
  type GarageCar,
  type NewGuest,
  type NewRegistrationPayload,
  type RegistrationOptions,
} from '../checkin';

const props = defineProps<{ showId: number }>();
const emit = defineEmits<{ created: [row: CheckinRow]; close: [] }>();

const toast = useToast();
const options = ref<RegistrationOptions | null>(null);
const loadError = ref<string | null>(null);
const alert = ref<string | null>(null);
const busy = ref(false);

const memberQuery = ref('');
const memberId = ref<number | null>(null);
const memberLabel = ref('');
const cars = ref<GarageCar[]>([]);
const carPicked = ref<Record<number, boolean>>({});
const judging = ref<Record<number, number | null>>({});
const arrivalDate = ref('');
const arrivalTime = ref('');
const donation = ref(0);
const guests = ref<NewGuest[]>([]);
const shirts = ref<Array<{ type_id: number | null }>>([]);
const extrasQty = ref<Record<number, number>>({});

const suggestions = computed(() => {
  const terms = memberQuery.value.toLowerCase().split(/\s+/).filter(Boolean);
  const all = options.value?.eligible_users ?? [];
  const matches = terms.length === 0 ? all : all.filter((user) => terms.every((term) => user.label.toLowerCase().includes(term)));
  return matches.slice(0, 120);
});

const pickedCars = computed(() => cars.value.filter((car) => carPicked.value[car.id]));

function extrasFor(extra: RegistrationOptions['extras'][number]): number {
  return quantityRange(extra, pickedCars.value.length, guests.value.length);
}

function quantityChoices(extra: RegistrationOptions['extras'][number]): number[] {
  return Array.from({ length: extrasFor(extra) + 1 }, (_, index) => index);
}

async function load(): Promise<void> {
  try {
    options.value = await checkinApi.options(props.showId);
    donation.value = 0;
  } catch (error) {
    loadError.value = failureMessage(error, 'The form could not be loaded.');
  }
}

async function pickMember(userId: number, label: string): Promise<void> {
  memberId.value = userId;
  memberLabel.value = label;
  memberQuery.value = label;
  carPicked.value = {};
  judging.value = {};
  cars.value = [];
  try {
    cars.value = await checkinApi.garage(props.showId, userId);
    if (cars.value.length === 0) alert.value = 'No cars in this garage.';
  } catch (error) {
    alert.value = failureMessage(error, 'Could not load garage.');
  }
}

function clearMember(): void {
  memberId.value = null;
  memberLabel.value = '';
  memberQuery.value = '';
  cars.value = [];
}

function toggleCar(car: GarageCar, picked: boolean): void {
  carPicked.value = { ...carPicked.value, [car.id]: picked };
  if (!picked) judging.value = { ...judging.value, [car.id]: null };
}

function setJudging(car: GarageCar, value: string): void {
  judging.value = { ...judging.value, [car.id]: value === '' ? null : Number(value) };
}

function addGuest(): void {
  guests.value = [...guests.value, { first: '', last: '', email: '', type_id: null }];
}

function removeGuest(index: number): void {
  guests.value = guests.value.filter((_, position) => position !== index);
}

function addShirt(): void {
  shirts.value = [...shirts.value, { type_id: null }];
}

function removeShirt(index: number): void {
  shirts.value = shirts.value.filter((_, position) => position !== index);
}

function fail(message: string): void {
  alert.value = message;
}

function payload(): NewRegistrationPayload | null {
  if (memberId.value === null) {
    fail('Choose a member from the list (type to filter, then click a row).');
    return null;
  }
  if (!arrivalDate.value || !arrivalTime.value) {
    fail('Arrival day and approximate time are required.');
    return null;
  }
  const carRows = pickedCars.value.map((car) => ({ id: car.id, judging_type_id: judging.value[car.id] ?? null }));
  if (carRows.length === 0) {
    fail('Select at least one car.');
    return null;
  }
  const guestRows: NewGuest[] = [];
  for (const guest of guests.value) {
    const started = guest.first || guest.last || guest.email || guest.type_id;
    if (!started) continue;
    if (!guest.first.trim() || !guest.last.trim() || !guest.email.trim() || !guest.type_id) {
      fail('Each guest row must include first name, last name, email, and type (or clear the row).');
      return null;
    }
    guestRows.push({ first: guest.first.trim(), last: guest.last.trim(), email: guest.email.trim(), type_id: guest.type_id });
  }
  const shirtRows: Array<{ type_id: number }> = [];
  for (const shirt of shirts.value) {
    if (!shirt.type_id) {
      fail('Each shirt row must include a size (or remove the row).');
      return null;
    }
    shirtRows.push({ type_id: shirt.type_id });
  }
  const extraRows = (options.value?.extras ?? [])
    .filter((extra) => (extrasQty.value[extra.id] ?? 0) > 0)
    .map((extra) => ({ show_extra_id: extra.id, num_extras: extrasQty.value[extra.id] ?? 0 }));
  return {
    user_id: memberId.value,
    arrival_date: arrivalDate.value,
    arrival_time: arrivalTime.value,
    donation: donation.value,
    cars: carRows,
    guests: guestRows,
    shirts: shirtRows,
    extras: extraRows,
  };
}

async function submit(): Promise<void> {
  const body = payload();
  if (body === null) return;
  busy.value = true;
  alert.value = null;
  try {
    const row = await checkinApi.createRegistration(props.showId, body);
    toast.success('Registration created. Waiver reminder and payment link emails sent.');
    emit('created', row);
    emit('close');
  } catch (error) {
    alert.value = failureMessage(error, 'Registration failed');
  } finally {
    busy.value = false;
  }
}

onMounted(() => void load());
</script>

<template>
  <BaseModal
    :open="true"
    title="New registration"
    size="lg"
    @update:open="(value: boolean) => { if (!value) emit('close'); }"
  >
    <p class="text-sm text-muted">
      Same details as online registration. The member receives a waiver reminder and a link to pay. Only members with at least one car
      in their garage can be added.
    </p>
    <p
      v-if="alert"
      role="alert"
      class="my-3 rounded-md border border-danger-border bg-danger-bg p-3 text-sm"
    >
      {{ alert }}
    </p>
    <p
      v-if="loadError"
      role="alert"
      class="rounded-md border border-danger-border bg-danger-bg p-3 text-sm"
    >
      {{ loadError }}
    </p>

    <div
      v-if="options"
      class="space-y-6"
    >
      <fieldset class="space-y-2">
        <legend class="font-semibold">
          Member
        </legend>
        <label
          for="walkin-member"
          class="block text-sm"
        >Site user (not yet registered for this event)</label>
        <input
          id="walkin-member"
          v-model="memberQuery"
          type="text"
          autocomplete="off"
          placeholder="Type name or email to filter…"
          class="min-h-12 w-full rounded-md border border-border bg-surface px-3 text-base"
          @input="memberId = null"
        >
        <div
          v-if="memberId === null && memberQuery.trim() !== ''"
          class="max-h-60 overflow-y-auto rounded-md border border-border"
        >
          <p
            v-if="suggestions.length === 0"
            class="p-3 text-sm text-muted"
          >
            No matches.
          </p>
          <ul v-else>
            <li
              v-for="user in suggestions"
              :key="user.id"
            >
              <button
                type="button"
                class="min-h-12 w-full px-3 py-2 text-left text-base hover:bg-subtle"
                @click="pickMember(user.id, user.label)"
              >
                {{ user.label }}
              </button>
            </li>
          </ul>
        </div>
        <p
          v-if="memberId !== null"
          class="flex items-center justify-between text-sm"
        >
          <span>Selected: <strong>{{ memberLabel }}</strong></span>
          <button
            type="button"
            class="min-h-12 px-3 underline"
            @click="clearMember"
          >
            Clear member
          </button>
        </p>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="font-semibold">
          Cars from garage
        </legend>
        <p
          v-if="memberId === null"
          class="text-sm text-muted"
        >
          Select a member to load their garage.
        </p>
        <ul
          v-else
          class="space-y-2"
        >
          <li
            v-for="car in cars"
            :key="car.id"
            class="rounded-md border border-border p-3"
          >
            <label class="flex min-h-12 items-center gap-3">
              <input
                type="checkbox"
                class="h-6 w-6"
                :checked="!!carPicked[car.id]"
                @change="toggleCar(car, ($event.target as HTMLInputElement).checked)"
              >
              <span>{{ car.label }}</span>
            </label>
            <label
              v-if="car.judging_types.length > 0"
              class="mt-2 block text-sm"
            >
              Judging
              <select
                class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
                :disabled="!carPicked[car.id]"
                :value="judging[car.id] ?? ''"
                @change="setJudging(car, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">
                  Not judged
                </option>
                <option
                  v-for="type in car.judging_types"
                  :key="type.id"
                  :value="type.id"
                >
                  {{ type.name }}
                </option>
              </select>
            </label>
          </li>
        </ul>
      </fieldset>

      <fieldset class="grid gap-3 sm:grid-cols-2">
        <legend class="font-semibold">
          Arrival
        </legend>
        <label class="text-sm">
          Date
          <select
            v-model="arrivalDate"
            class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
          >
            <option value="">
              — Select —
            </option>
            <option
              v-for="day in options.show_dates"
              :key="day.data"
              :value="day.data"
            >
              {{ day.name }} ({{ day.data }})
            </option>
          </select>
        </label>
        <label class="text-sm">
          Approximate arrival
          <select
            v-model="arrivalTime"
            class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
          >
            <option value="">
              — Select —
            </option>
            <option
              v-for="time in options.arrival_times"
              :key="time"
              :value="time"
            >
              {{ time.charAt(0).toUpperCase() + time.slice(1) }}
            </option>
          </select>
        </label>
      </fieldset>

      <fieldset>
        <legend class="font-semibold">
          Optional donation
        </legend>
        <select
          v-model.number="donation"
          class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2 sm:w-64"
        >
          <option
            v-for="choice in options.donations"
            :key="choice.id"
            :value="choice.id"
          >
            {{ choice.name }}
          </option>
        </select>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="font-semibold">
          Guests
        </legend>
        <div
          v-for="(guest, index) in guests"
          :key="index"
          class="grid gap-2 rounded-md border border-border p-3 sm:grid-cols-2"
        >
          <label class="text-sm">First <input
            v-model="guest.first"
            maxlength="24"
            class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
          ></label>
          <label class="text-sm">Last <input
            v-model="guest.last"
            maxlength="24"
            class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
          ></label>
          <label class="text-sm">Email <input
            v-model="guest.email"
            type="email"
            class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
          ></label>
          <label class="text-sm">Type
            <select
              v-model.number="guest.type_id"
              class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-2"
            >
              <option :value="null">
                —
              </option>
              <option
                v-for="type in options.guest_types"
                :key="type.id"
                :value="type.id"
              >
                {{ type.name }}
              </option>
            </select>
          </label>
          <button
            type="button"
            class="min-h-12 justify-self-start px-3 underline"
            @click="removeGuest(index)"
          >
            Remove guest
          </button>
        </div>
        <button
          type="button"
          class="min-h-12 rounded-md border border-border px-4"
          @click="addGuest"
        >
          Add guest
        </button>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="font-semibold">
          Shirts
        </legend>
        <div
          v-for="(shirt, index) in shirts"
          :key="index"
          class="flex flex-wrap items-center gap-2"
        >
          <select
            v-model.number="shirt.type_id"
            class="min-h-12 min-w-0 flex-1 rounded-md border border-border bg-surface px-2"
            aria-label="Shirt size"
          >
            <option :value="null">
              Please Select
            </option>
            <option
              v-for="type in options.shirt_types"
              :key="type.id"
              :value="type.id"
            >
              {{ type.name }}{{ type.price_cents === null ? '' : ` - ${formatCents(type.price_cents)}` }}
            </option>
          </select>
          <button
            type="button"
            class="min-h-12 px-3 underline"
            @click="removeShirt(index)"
          >
            Remove shirt
          </button>
        </div>
        <button
          type="button"
          class="min-h-12 rounded-md border border-border px-4"
          @click="addShirt"
        >
          Add shirt
        </button>
      </fieldset>

      <fieldset
        v-if="options.extras.length > 0"
        class="space-y-2"
      >
        <legend class="font-semibold">
          Extras
        </legend>
        <div
          v-for="extra in options.extras"
          :key="extra.id"
          class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-3"
        >
          <div>
            <p class="font-medium">
              {{ extra.name }}
            </p>
            <p
              v-if="extra.description"
              class="text-sm text-muted"
            >
              {{ extra.description }}
            </p>
          </div>
          <label class="text-sm">
            Quantity
            <select
              v-model.number="extrasQty[extra.id]"
              class="mt-1 min-h-12 rounded-md border border-border bg-surface px-2"
            >
              <option
                v-for="choice in quantityChoices(extra)"
                :key="choice"
                :value="choice"
              >
                {{ choice }}
              </option>
            </select>
          </label>
        </div>
      </fieldset>
    </div>

    <template #footer>
      <BaseButton
        size="lg"
        class="min-h-12"
        @click="emit('close')"
      >
        Cancel
      </BaseButton>
      <BaseButton
        variant="primary"
        size="lg"
        class="min-h-12"
        :loading="busy"
        :disabled="!options"
        @click="submit"
      >
        Create &amp; email
      </BaseButton>
    </template>
  </BaseModal>
</template>
