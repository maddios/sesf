<script setup lang="ts">
import { RouterLink } from 'vue-router';
import FormField from '../FormField.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { useFormValues } from './formContext';
import type { RegistrableCar } from '../../api/registration';

defineProps<{
  cars: RegistrableCar[];
  readOnly: boolean;
  returnTo: string;
  fieldError: (path: string) => string | undefined;
  touch: (path: string) => void;
  messages: string[];
  domId: string;
}>();

function choiceFor(carId: number) {
  return form.cars.find((choice) => choice.id === carId);
}

function toggle(car: RegistrableCar): void {
  const index = form.cars.findIndex((choice) => choice.id === car.id);
  if (index >= 0) {
    form.cars.splice(index, 1);
    return;
  }
  // A single allowed judging type is the only choice, so it is picked for the member.
  const only = car.judgingTypes.length === 1 ? car.judgingTypes[0] : undefined;
  form.cars.push({ id: car.id, judgingType: only?.id ?? null });
}

function carLabel(car: RegistrableCar): string {
  const title = car.nickname || [car.year, car.model].filter(Boolean).join(' ') || car.name;
  return car.name && car.name !== title ? `${title} (${car.name})` : title;
}

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Cars"
    :step="1"
    hint="Choose the cars you will bring. Your first car is required and does not include a guest; each additional car includes one free guest."
    :messages="messages"
  >
    <p
      v-if="cars.length === 0"
      class="text-sm"
    >
      You have no cars to register yet.
    </p>
    <ul
      v-else
      class="space-y-3"
    >
      <li
        v-for="car in cars"
        :key="car.id"
        class="rounded-sm border border-border p-3"
      >
        <div class="flex items-start gap-3">
          <input
            :id="`car-${car.id}`"
            type="checkbox"
            class="mt-1 h-4 w-4"
            :checked="choiceFor(car.id) !== undefined"
            :disabled="readOnly"
            @change="toggle(car)"
          >
          <label
            :for="`car-${car.id}`"
            class="font-medium text-text"
          >{{ carLabel(car) }}</label>
        </div>
        <div
          v-if="choiceFor(car.id) !== undefined && car.judgingTypes.length > 0"
          class="mt-3 max-w-xs"
        >
          <FormField
            :id="`car-${car.id}-judging`"
            v-slot="{ inputProps }"
            label="Judging type"
            :errors="messageList(fieldError(`cars.${car.id}.judgingType`))"
          >
            <select
              v-bind="inputProps"
              class="form-control"
              :value="choiceFor(car.id)?.judgingType ?? ''"
              @change="choiceFor(car.id)!.judgingType = Number(($event.target as HTMLSelectElement).value) || null"
              @blur="touch(`cars.${car.id}.judgingType`)"
            >
              <option value="">
                Please select
              </option>
              <option
                v-for="type in car.judgingTypes"
                :key="type.id"
                :value="type.id"
              >
                {{ type.name }}
              </option>
            </select>
          </FormField>
        </div>
      </li>
    </ul>
    <p class="text-sm">
      <RouterLink
        :to="{ path: '/car/new', query: { return: returnTo } }"
        class="font-medium"
      >
        Add a new car
      </RouterLink>
      <span class="text-muted"> Your choices here are kept while you add it.</span>
    </p>
  </SectionShell>
</template>
