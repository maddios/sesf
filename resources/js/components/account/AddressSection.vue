<script setup lang="ts">
/** Mailing address. Loaded on mount; the server validates state (two letters), zip and field lengths. */
import { onMounted, reactive, ref } from 'vue';
import { fetchLocation, saveLocation, type AddressInfo } from '../../api/account';
import { useSaveState } from '../../composables/account/useSaveState';
import { hasErrors, LIMITS, validateAddress, type FieldErrors } from '../../composables/account/validation';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';

const loadState = ref<'loading' | 'ready' | 'error'>('loading');
const form = reactive<AddressInfo>({ address1: '', address2: '', city: '', state: '', zip: '' });
const errors = reactive<FieldErrors<keyof AddressInfo>>({});
const saveState = useSaveState();

async function load(): Promise<void> {
  loadState.value = 'loading';
  try {
    Object.assign(form, await fetchLocation());
    loadState.value = 'ready';
  } catch {
    loadState.value = 'error';
  }
}

async function onSubmit(): Promise<void> {
  saveState.reset();
  const next = validateAddress(form);
  for (const key of Object.keys(errors) as (keyof AddressInfo)[]) {
    delete errors[key];
  }
  Object.assign(errors, next);
  if (hasErrors(next)) {
    return;
  }
  const input: AddressInfo = { ...form, state: form.state.toUpperCase() };
  await saveState.run(() => saveLocation(input), 'Address saved.');
}

onMounted(() => void load());
</script>

<template>
  <BaseSpinner
    v-if="loadState === 'loading'"
    label="Loading address"
    class="py-4 text-brand"
  />
  <BaseAlert
    v-else-if="loadState === 'error'"
    variant="error"
  >
    <p>Your address could not be loaded.</p>
    <button
      type="button"
      class="underline"
      @click="load()"
    >
      Try again
    </button>
  </BaseAlert>
  <form
    v-else
    class="space-y-4"
    novalidate
    @submit.prevent="onSubmit"
  >
    <FormField
      id="address-1"
      v-slot="{ inputProps }"
      label="Address"
      :errors="errors.address1 ? [errors.address1] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.address1"
        class="form-control w-full"
        type="text"
        autocomplete="address-line1"
        :maxlength="LIMITS.address"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <FormField
      id="address-2"
      v-slot="{ inputProps }"
      label="Address line 2"
      :errors="errors.address2 ? [errors.address2] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.address2"
        class="form-control w-full"
        type="text"
        autocomplete="address-line2"
        :maxlength="LIMITS.address"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <div class="grid gap-4 sm:grid-cols-3">
      <FormField
        id="address-city"
        v-slot="{ inputProps }"
        label="City"
        :errors="errors.city ? [errors.city] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.city"
          class="form-control w-full"
          type="text"
          autocomplete="address-level2"
          :maxlength="LIMITS.address"
          :disabled="saveState.busy.value"
        >
      </FormField>
      <FormField
        id="address-state"
        v-slot="{ inputProps }"
        label="State"
        hint="Two-letter code"
        :errors="errors.state ? [errors.state] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.state"
          class="form-control w-full"
          type="text"
          autocomplete="address-level1"
          :disabled="saveState.busy.value"
        >
      </FormField>
      <FormField
        id="address-zip"
        v-slot="{ inputProps }"
        label="Zip code"
        :errors="errors.zip ? [errors.zip] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.zip"
          class="form-control w-full"
          type="text"
          autocomplete="postal-code"
          :maxlength="LIMITS.zip"
          :disabled="saveState.busy.value"
        >
      </FormField>
    </div>

    <ResponseBanner
      :messages="saveState.messages.value"
      variant="error"
    />
    <BaseAlert
      v-if="saveState.success.value"
      variant="success"
    >
      {{ saveState.success.value }}
    </BaseAlert>
    <SubmitButton
      :busy="saveState.busy.value"
      busy-label="Saving address"
    >
      Save address
    </SubmitButton>
  </form>
</template>
