<script setup lang="ts">
/**
 * Add or edit a car (legacy car/new and car/:id/settings). Body, type, year, model and VIN are locked when the
 * car is registered for a show: they are shown disabled with a note, since the server ignores them. A new car
 * continues to the photo step, as the legacy new_image page did.
 */
import { computed, reactive, ref, shallowRef, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import {
  fetchCarBodies,
  fetchCarDetail,
  fetchCarTypes,
  saveCar,
  type CarDetail,
  type CarInput,
  type CarOption,
} from '../../api/account';
import { ApiError } from '../../api/client';
import { useSaveState } from '../../composables/account/useSaveState';
import { hasErrors, LIMITS, validateCar, type CarField, type FieldErrors } from '../../composables/account/validation';
import FormField from '../../components/FormField.vue';
import ResponseBanner from '../../components/ResponseBanner.vue';
import SubmitButton from '../../components/SubmitButton.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';

const props = defineProps<{ carId?: number }>();

const router = useRouter();
type Status = 'loading' | 'ready' | 'notFound' | 'error';

const isCreate = computed(() => props.carId === undefined);
const status = ref<Status>('loading');
const bodies = shallowRef<CarOption[]>([]);
const types = shallowRef<CarOption[]>([]);
const detail = shallowRef<CarDetail | null>(null);
const form = reactive({ nickname: '', model: '', year: '', vin: '', bodyId: 0, typeId: 0 });
const errors = ref<FieldErrors<CarField>>({});
const saveState = useSaveState();

const locked = computed(() => detail.value?.locked === true);
const heading = computed(() => (isCreate.value ? 'Add a car' : 'Edit car'));

function applyDetail(car: CarDetail): void {
  form.nickname = car.nickname;
  form.model = car.model;
  form.year = car.year;
  form.vin = car.vin;
  form.bodyId = car.bodyId ?? 0;
  form.typeId = car.typeId ?? 0;
}

async function load(): Promise<void> {
  const carId = props.carId;
  status.value = 'loading';
  try {
    const [bodyList, typeList, car] = await Promise.all([
      fetchCarBodies(),
      fetchCarTypes(),
      carId === undefined ? Promise.resolve(null) : fetchCarDetail(carId),
    ]);
    bodies.value = bodyList;
    types.value = typeList;
    detail.value = car;
    if (car !== null) {
      applyDetail(car);
    }
    status.value = 'ready';
  } catch (failure) {
    detail.value = null;
    status.value = failure instanceof ApiError && failure.status === 404 ? 'notFound' : 'error';
  }
}

watch(() => props.carId, () => void load(), { immediate: true });

async function onSubmit(): Promise<void> {
  saveState.reset();
  const input: CarInput = {
    ...(props.carId === undefined ? {} : { id: props.carId }),
    nickname: form.nickname.trim(),
    model: form.model.trim(),
    year: form.year.trim(),
    vin: form.vin.trim(),
    bodyId: form.bodyId,
    typeId: form.typeId,
  };
  errors.value = validateCar(input, locked.value);
  if (hasErrors(errors.value)) {
    return;
  }
  let savedId = 0;
  const ok = await saveState.run(async () => {
    savedId = await saveCar(input);
  }, 'Car saved.');
  if (ok && isCreate.value && savedId > 0) {
    await router.push(`/car/${savedId}/new-image`);
  }
}
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-6">
    <h1 class="page-title">
      {{ heading }}
    </h1>

    <BaseSpinner
      v-if="status === 'loading'"
      label="Loading car form"
      class="py-6 text-brand"
    />
    <div
      v-else-if="status === 'notFound'"
      class="space-y-3"
    >
      <p>This car does not exist or is not in your garage.</p>
    </div>
    <BaseAlert
      v-else-if="status === 'error'"
      variant="error"
    >
      <p>The car form could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <form
      v-else
      class="space-y-4"
      novalidate
      @submit.prevent="onSubmit"
    >
      <BaseAlert
        v-if="locked"
        variant="info"
      >
        This car is registered for a show. Its body, type, year, model and VIN are locked and cannot be changed here.
      </BaseAlert>

      <FormField
        id="car-nickname"
        v-slot="{ inputProps }"
        label="Nickname"
        :hint="`${LIMITS.nickname.min} to ${LIMITS.nickname.max} characters.`"
        :errors="errors.nickname ? [errors.nickname] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.nickname"
          class="form-control w-full"
          type="text"
          :maxlength="LIMITS.nickname.max"
          :disabled="saveState.busy.value"
        >
      </FormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          id="car-body"
          v-slot="{ inputProps }"
          label="Body style"
          :errors="errors.bodyId ? [errors.bodyId] : []"
        >
          <select
            v-bind="inputProps"
            v-model.number="form.bodyId"
            class="form-control w-full"
            :disabled="locked || saveState.busy.value"
          >
            <option :value="0">
              Choose a body style
            </option>
            <option
              v-for="body in bodies"
              :key="body.id"
              :value="body.id"
            >
              {{ body.name }}
            </option>
          </select>
        </FormField>
        <FormField
          id="car-type"
          v-slot="{ inputProps }"
          label="Type"
          :errors="errors.typeId ? [errors.typeId] : []"
        >
          <select
            v-bind="inputProps"
            v-model.number="form.typeId"
            class="form-control w-full"
            :disabled="locked || saveState.busy.value"
          >
            <option :value="0">
              Choose a type
            </option>
            <option
              v-for="type in types"
              :key="type.id"
              :value="type.id"
            >
              {{ type.name }}
            </option>
          </select>
        </FormField>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          id="car-year"
          v-slot="{ inputProps }"
          label="Year"
          :errors="errors.year ? [errors.year] : []"
        >
          <input
            v-bind="inputProps"
            v-model="form.year"
            class="form-control w-full"
            type="text"
            inputmode="numeric"
            :maxlength="LIMITS.year"
            :disabled="locked || saveState.busy.value"
          >
        </FormField>
        <FormField
          id="car-model"
          v-slot="{ inputProps }"
          label="Model"
          :errors="errors.model ? [errors.model] : []"
        >
          <input
            v-bind="inputProps"
            v-model="form.model"
            class="form-control w-full"
            type="text"
            :maxlength="LIMITS.model"
            :disabled="locked || saveState.busy.value"
          >
        </FormField>
      </div>

      <FormField
        id="car-vin"
        v-slot="{ inputProps }"
        label="VIN"
        hint="Optional. 5 to 7 letters or digits."
        :errors="errors.vin ? [errors.vin] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.vin"
          class="form-control w-full"
          type="text"
          autocomplete="off"
          :maxlength="LIMITS.vin.max"
          :disabled="locked || saveState.busy.value"
        >
      </FormField>

      <ResponseBanner
        :messages="saveState.messages.value"
        variant="error"
      />
      <BaseAlert
        v-if="saveState.success.value"
        variant="success"
      >
        {{ saveState.success.value }}
        <RouterLink
          v-if="!isCreate && props.carId !== undefined"
          :to="`/car/${props.carId}/new-image`"
        >
          Change the photo
        </RouterLink>
      </BaseAlert>

      <div class="flex flex-wrap items-center gap-3">
        <SubmitButton
          :busy="saveState.busy.value"
          :busy-label="isCreate ? 'Adding car' : 'Saving car'"
        >
          {{ isCreate ? 'Add car and continue' : 'Save car' }}
        </SubmitButton>
        <BaseButton
          v-if="!isCreate && props.carId !== undefined"
          :to="`/car/${props.carId}`"
        >
          View car
        </BaseButton>
      </div>
    </form>
  </section>
</template>
