<script setup lang="ts">
/**
 * "New registration" at the check-in table (legacy checkin_list add modal): a member with a car who is not registered
 * yet, their garage cars, arrival, donation, guests, shirts and extras. Create sends the waiver reminder and billing
 * link emails, as legacy did.
 */
import { computed, onMounted, ref } from 'vue';
import BaseCombobox, { type ComboboxOption, type ComboboxValue } from '../../../../components/ui/BaseCombobox.vue';
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

const memberId = ref<number | null>(null);
const cars = ref<GarageCar[]>([]);
const carPicked = ref<Record<number, boolean>>({});
const judging = ref<Record<number, number | null>>({});
const arrivalDate = ref('');
const arrivalTime = ref('');
const donation = ref(0);
const guests = ref<NewGuest[]>([]);
const shirts = ref<Array<{ type_id: number | null }>>([]);
const extrasQty = ref<Record<number, number>>({});

const memberOptions = computed<ComboboxOption[]>(() =>
  (options.value?.eligible_users ?? []).map((user) => ({ value: user.id, label: user.label })),
);
const dateOptions = computed<ComboboxOption[]>(() =>
  (options.value?.show_dates ?? []).map((day) => ({ value: day.data, label: `${day.name} (${day.data})` })),
);
const timeOptions = computed<ComboboxOption[]>(() =>
  (options.value?.arrival_times ?? []).map((time) => ({ value: time, label: time.charAt(0).toUpperCase() + time.slice(1) })),
);
const donationOptions = computed<ComboboxOption[]>(() =>
  (options.value?.donations ?? []).map((choice) => ({ value: choice.id, label: choice.name })),
);
const guestTypeOptions = computed<ComboboxOption[]>(() =>
  (options.value?.guest_types ?? []).map((type) => ({ value: type.id, label: type.name })),
);
const shirtOptions = computed<ComboboxOption[]>(() =>
  (options.value?.shirt_types ?? []).map((type) => ({
    value: type.id,
    label: `${type.name}${type.price_cents === null ? '' : ` - ${formatCents(type.price_cents)}`}`,
  })),
);

const pickedCars = computed(() => cars.value.filter((car) => carPicked.value[car.id]));

function extrasFor(extra: RegistrationOptions['extras'][number]): number {
  return quantityRange(extra, pickedCars.value.length, guests.value.length);
}

function quantityOptions(extra: RegistrationOptions['extras'][number]): ComboboxOption[] {
  return Array.from({ length: extrasFor(extra) + 1 }, (_, index) => ({ value: index, label: String(index) }));
}

function judgingOptions(car: GarageCar): ComboboxOption[] {
  return [{ value: '', label: 'Not judged' }, ...car.judging_types.map((type) => ({ value: type.id, label: type.name }))];
}

async function load(): Promise<void> {
  try {
    options.value = await checkinApi.options(props.showId);
    donation.value = 0;
  } catch (error) {
    loadError.value = failureMessage(error, 'The form could not be loaded.');
  }
}

async function pickMember(userId: number): Promise<void> {
  memberId.value = userId;
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

function onMemberChange(option: ComboboxOption | null): void {
  if (option === null) {
    memberId.value = null;
    cars.value = [];
    return;
  }
  void pickMember(Number(option.value));
}

function toggleCar(car: GarageCar, picked: boolean): void {
  carPicked.value = { ...carPicked.value, [car.id]: picked };
  if (!picked) judging.value = { ...judging.value, [car.id]: null };
}

function setJudging(car: GarageCar, value: ComboboxValue | null): void {
  judging.value = { ...judging.value, [car.id]: value === null || value === '' ? null : Number(value) };
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
    const { row, queued } = await checkinApi.createRegistration(props.showId, body);
    if (queued) {
      toast.success('Registration created. Waiver reminder and payment link emails queued.');
    } else {
      toast.error('Registration created, but the waiver reminder and payment link emails could not be queued. Send them from the check-in modal.');
    }
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
    sheet
    :open="true"
    title="New registration"
    size="lg"
    @update:open="(value: boolean) => { if (!value) emit('close'); }"
  >
    <div class="a2-page">
      <p class="a2-muted">
        Same details as online registration. The member receives a waiver reminder and a link to pay. Only members with at least one car
        in their garage can be added.
      </p>
      <p
        v-if="alert"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ alert }}
      </p>
      <p
        v-if="loadError"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ loadError }}
      </p>

      <div v-if="options">
        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Member
          </legend>
          <label
            for="walkin-member"
            class="a2-modal-label"
          >Site user (not yet registered for this event)</label>
          <BaseCombobox
            id="walkin-member"
            :model-value="memberId"
            :options="memberOptions"
            clearable
            placeholder="Type name or email to filter…"
            @change="onMemberChange"
          />
        </fieldset>

        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Cars from garage
          </legend>
          <p
            v-if="memberId === null"
            class="a2-muted"
          >
            Select a member to load their garage.
          </p>
          <div
            v-for="car in cars"
            :key="car.id"
            class="a2-subtle-row"
          >
            <label class="a2-check">
              <input
                type="checkbox"
                :checked="!!carPicked[car.id]"
                @change="toggleCar(car, ($event.target as HTMLInputElement).checked)"
              >
              {{ car.label }}
            </label>
            <div
              v-if="car.judging_types.length > 0"
              class="mt-2"
            >
              <label
                :for="`judging-${car.id}`"
                class="a2-modal-label"
              >Judging</label>
              <BaseCombobox
                :id="`judging-${car.id}`"
                :model-value="judging[car.id] ?? ''"
                :options="judgingOptions(car)"
                :disabled="!carPicked[car.id]"
                @update:model-value="(value: ComboboxValue | null) => setJudging(car, value)"
              />
            </div>
          </div>
        </fieldset>

        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Arrival
          </legend>
          <label
            for="walkin-arrival-date"
            class="a2-modal-label"
          >Date</label>
          <BaseCombobox
            id="walkin-arrival-date"
            :model-value="arrivalDate || null"
            :options="dateOptions"
            placeholder="— Select —"
            @update:model-value="(value: ComboboxValue | null) => (arrivalDate = String(value))"
          />
          <label
            for="walkin-arrival-time"
            class="a2-modal-label"
          >Approximate arrival</label>
          <BaseCombobox
            id="walkin-arrival-time"
            :model-value="arrivalTime || null"
            :options="timeOptions"
            placeholder="— Select —"
            @update:model-value="(value: ComboboxValue | null) => (arrivalTime = String(value))"
          />
        </fieldset>

        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Optional donation
          </legend>
          <BaseCombobox
            id="walkin-donation"
            :model-value="donation"
            :options="donationOptions"
            aria-label="Optional donation"
            @update:model-value="(value: ComboboxValue | null) => (donation = Number(value))"
          />
        </fieldset>

        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Guests
          </legend>
          <div
            v-for="(guest, index) in guests"
            :key="index"
            class="a2-subtle-row"
          >
            <div class="grid gap-2 @sm:grid-cols-2">
              <label class="a2-modal-label">First
                <input
                  v-model="guest.first"
                  maxlength="24"
                  class="a2-input a2-input--block"
                ></label>
              <label class="a2-modal-label">Last
                <input
                  v-model="guest.last"
                  maxlength="24"
                  class="a2-input a2-input--block"
                ></label>
              <label class="a2-modal-label">Email
                <input
                  v-model="guest.email"
                  type="email"
                  class="a2-input a2-input--block"
                ></label>
              <div>
                <span class="a2-modal-label">Type</span>
                <BaseCombobox
                  aria-label="Guest type"
                  :model-value="guest.type_id"
                  :options="guestTypeOptions"
                  placeholder="—"
                  @update:model-value="(value: ComboboxValue | null) => (guest.type_id = Number(value))"
                />
              </div>
            </div>
            <button
              type="button"
              class="a2-btn a2-btn-small mt-2"
              @click="removeGuest(index)"
            >
              Remove guest
            </button>
          </div>
          <button
            type="button"
            class="a2-btn"
            @click="addGuest"
          >
            Add guest
          </button>
        </fieldset>

        <fieldset class="a2-fieldset">
          <legend class="a2-modal-label">
            Shirts
          </legend>
          <div
            v-for="(shirt, index) in shirts"
            :key="index"
            class="a2-subtle-row flex flex-wrap items-center gap-2"
          >
            <div class="min-w-0 flex-1">
              <BaseCombobox
                aria-label="Shirt size"
                :model-value="shirt.type_id"
                :options="shirtOptions"
                placeholder="Please Select"
                @update:model-value="(value: ComboboxValue | null) => (shirt.type_id = Number(value))"
              />
            </div>
            <button
              type="button"
              class="a2-btn a2-btn-small"
              @click="removeShirt(index)"
            >
              Remove shirt
            </button>
          </div>
          <button
            type="button"
            class="a2-btn"
            @click="addShirt"
          >
            Add shirt
          </button>
        </fieldset>

        <fieldset
          v-if="options.extras.length > 0"
          class="a2-fieldset"
        >
          <legend class="a2-modal-label">
            Extras
          </legend>
          <div
            v-for="extra in options.extras"
            :key="extra.id"
            class="a2-subtle-row flex flex-wrap items-center justify-between gap-2"
          >
            <div>
              <p class="font-medium">
                {{ extra.name }}
              </p>
              <p
                v-if="extra.description"
                class="a2-muted a2-small"
              >
                {{ extra.description }}
              </p>
            </div>
            <div>
              <label
                :for="`extra-qty-${extra.id}`"
                class="a2-modal-label"
              >Quantity</label>
              <BaseCombobox
                :id="`extra-qty-${extra.id}`"
                :model-value="extrasQty[extra.id] ?? 0"
                :options="quantityOptions(extra)"
                @update:model-value="(value: ComboboxValue | null) => (extrasQty[extra.id] = Number(value))"
              />
            </div>
          </div>
        </fieldset>
      </div>
    </div>

    <template #footer>
      <div class="a2-modal-actions">
        <button
          type="button"
          class="a2-btn a2-btn-info"
          :disabled="busy || !options"
          @click="submit"
        >
          Create &amp; email
        </button>
        <button
          type="button"
          class="a2-btn"
          @click="emit('close')"
        >
          Cancel
        </button>
      </div>
    </template>
  </BaseModal>
</template>
