<script setup lang="ts">
/**
 * Adds a car to judging: pick a registration, then its car, then a judging type offered for that car's body.
 */
import { computed, ref, watch } from 'vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useToast } from '../../../composables/admin/useToast';
import { addEntry, fetchTypes, fetchUnjudged, type JudgingType, type UnjudgedRegistration } from './judging';

const props = defineProps<{
  showId: number;
}>();

const emit = defineEmits<{
  added: [];
}>();

const toast = useToast();
const registrations = ref<UnjudgedRegistration[]>([]);
const types = ref<JudgingType[]>([]);
const registrationId = ref('');
const carId = ref('');
const judgingTypeId = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const formError = ref('');
const loadError = ref('');
const submitting = ref(false);
let typesSequence = 0;

const registration = computed(() => registrations.value.find((r) => String(r.registration_id) === registrationId.value) ?? null);
const cars = computed(() => registration.value?.cars ?? []);
const canSubmit = computed(() => carId.value !== '' && judgingTypeId.value !== '' && !submitting.value);

function fieldError(field: string): string | undefined {
  return fieldErrors.value[field]?.[0];
}

async function loadRegistrations(): Promise<void> {
  try {
    registrations.value = await fetchUnjudged(props.showId);
    loadError.value = '';
  } catch (error) {
    loadError.value = mapAdminError(error, 'The cars that can be added could not be loaded.').message;
  }
}

watch(
  () => props.showId,
  () => {
    registrationId.value = '';
    void loadRegistrations();
  },
  { immediate: true },
);

watch(registrationId, () => {
  carId.value = '';
});

watch(carId, async (value) => {
  judgingTypeId.value = '';
  types.value = [];
  const car = cars.value.find((c) => String(c.car_id) === value);
  if (car === undefined) return;
  const sequence = ++typesSequence;
  try {
    const loaded = await fetchTypes(props.showId, car.body_id);
    if (sequence === typesSequence) types.value = loaded;
  } catch (error) {
    if (sequence === typesSequence) formError.value = mapAdminError(error, 'The judging types could not be loaded.').message;
  }
});

async function submit(): Promise<void> {
  if (!canSubmit.value || registration.value === null) return;
  submitting.value = true;
  fieldErrors.value = {};
  formError.value = '';
  try {
    await addEntry(props.showId, {
      registration_id: registration.value.registration_id,
      car_id: Number(carId.value),
      judging_type_id: Number(judgingTypeId.value),
    });
    toast.success('Car added to judging.');
    registrationId.value = '';
    emit('added');
    await loadRegistrations();
  } catch (error) {
    const info = mapAdminError(error, 'The car could not be added.');
    formError.value = info.message;
    fieldErrors.value = info.fieldErrors;
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <section aria-labelledby="add-judging-heading">
    <h2
      id="add-judging-heading"
      class="text-lg font-semibold text-heading"
    >
      Add judging entry
    </h2>

    <p
      v-if="loadError"
      role="alert"
      class="mt-2 text-danger"
    >
      {{ loadError }}
    </p>
    <p
      v-else-if="registrations.length === 0"
      class="mt-2 text-muted"
    >
      Every registered car in this event is already in judging.
    </p>

    <form
      v-else
      class="mt-3 grid gap-3 sm:grid-cols-2"
      @submit.prevent="submit"
    >
      <div>
        <label
          for="judging-registration"
          class="block text-sm font-medium text-text"
        >Registration</label>
        <select
          id="judging-registration"
          v-model="registrationId"
          class="form-control mt-1"
          :aria-invalid="fieldError('registration_id') ? 'true' : undefined"
          :aria-describedby="fieldError('registration_id') ? 'judging-registration-error' : undefined"
        >
          <option value="">
            Choose a registration
          </option>
          <option
            v-for="item in registrations"
            :key="item.registration_id"
            :value="String(item.registration_id)"
          >
            {{ item.name }} (invoice {{ item.invoice_id }})
          </option>
        </select>
        <p
          v-if="fieldError('registration_id')"
          id="judging-registration-error"
          class="mt-1 text-danger"
        >
          {{ fieldError('registration_id') }}
        </p>
      </div>

      <div>
        <label
          for="judging-car"
          class="block text-sm font-medium text-text"
        >Car</label>
        <select
          id="judging-car"
          v-model="carId"
          class="form-control mt-1"
          :disabled="registration === null"
          :aria-invalid="fieldError('car_id') ? 'true' : undefined"
          :aria-describedby="fieldError('car_id') ? 'judging-car-error' : undefined"
        >
          <option value="">
            Choose a car
          </option>
          <option
            v-for="car in cars"
            :key="car.car_id"
            :value="String(car.car_id)"
          >
            {{ car.name }}
          </option>
        </select>
        <p
          v-if="fieldError('car_id')"
          id="judging-car-error"
          class="mt-1 text-danger"
        >
          {{ fieldError('car_id') }}
        </p>
      </div>

      <div>
        <label
          for="judging-type"
          class="block text-sm font-medium text-text"
        >Judging type</label>
        <select
          id="judging-type"
          v-model="judgingTypeId"
          class="form-control mt-1"
          :disabled="types.length === 0"
          :aria-invalid="fieldError('judging_type_id') ? 'true' : undefined"
          :aria-describedby="fieldError('judging_type_id') ? 'judging-type-error' : undefined"
        >
          <option value="">
            Choose a type
          </option>
          <option
            v-for="type in types"
            :key="type.id"
            :value="String(type.id)"
          >
            {{ type.name }}
          </option>
        </select>
        <p
          v-if="fieldError('judging_type_id')"
          id="judging-type-error"
          class="mt-1 text-danger"
        >
          {{ fieldError('judging_type_id') }}
        </p>
      </div>

      <div class="flex items-end">
        <BaseButton
          type="submit"
          variant="primary"
          :disabled="!canSubmit"
        >
          Add to judging
        </BaseButton>
      </div>

      <p
        v-if="formError"
        role="alert"
        class="text-danger sm:col-span-2"
      >
        {{ formError }}
      </p>
    </form>
  </section>
</template>
