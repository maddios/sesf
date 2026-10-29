<script setup lang="ts">
/**
 * Name, email and phone. Changing the email requires the current password (the server rejects the save
 * without it). When the signed-in user edits their own account, the session copy is updated after a save.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { fetchAccountInfo, saveAccountInfo, type AccountInfoInput } from '../../api/account';
import { useAuthStore } from '../../stores/auth';
import { useSaveState } from '../../composables/account/useSaveState';
import { hasErrors, LIMITS, validateAccountInfo, type AccountInfoField, type FieldErrors } from '../../composables/account/validation';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';

const props = defineProps<{ userId: number }>();

const auth = useAuthStore();
const loadState = ref<'loading' | 'ready' | 'error'>('loading');
const form = reactive<AccountInfoInput>({ first: '', last: '', email: '', phone: '', currentPassword: '' });
const savedEmail = ref('');
const errors = ref<FieldErrors<AccountInfoField>>({});
const saveState = useSaveState();

const emailChanged = computed(() => form.email.trim() !== savedEmail.value.trim());

async function load(): Promise<void> {
  loadState.value = 'loading';
  try {
    const info = await fetchAccountInfo(props.userId);
    form.first = info.first;
    form.last = info.last;
    form.email = info.email;
    form.phone = info.phone;
    savedEmail.value = info.email;
    loadState.value = 'ready';
  } catch {
    loadState.value = 'error';
  }
}

async function onSubmit(): Promise<void> {
  saveState.reset();
  errors.value = validateAccountInfo(form, savedEmail.value);
  if (hasErrors(errors.value)) {
    return;
  }
  const input: AccountInfoInput = {
    first: form.first.trim(),
    last: form.last.trim(),
    email: form.email.trim(),
    phone: form.phone.trim(),
    ...(emailChanged.value ? { currentPassword: form.currentPassword } : {}),
  };
  const ok = await saveState.run(() => saveAccountInfo(input), 'Account information saved.');
  if (!ok) {
    return;
  }
  savedEmail.value = input.email;
  form.currentPassword = '';
  if (auth.user !== null && auth.userId === props.userId) {
    auth.setUser({ ...auth.user, firstName: input.first, email: input.email });
  }
}

onMounted(() => void load());

</script>

<template>
  <BaseSpinner
    v-if="loadState === 'loading'"
    label="Loading account information"
    class="py-4 text-brand"
  />
  <BaseAlert
    v-else-if="loadState === 'error'"
    variant="error"
  >
    <p>Your account information could not be loaded.</p>
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
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField
        id="account-first"
        v-slot="{ inputProps }"
        label="First name"
        :errors="errors.first ? [errors.first] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.first"
          class="form-control w-full"
          type="text"
          autocomplete="given-name"
          :maxlength="LIMITS.name"
          :disabled="saveState.busy.value"
        >
      </FormField>
      <FormField
        id="account-last"
        v-slot="{ inputProps }"
        label="Last name"
        :errors="errors.last ? [errors.last] : []"
      >
        <input
          v-bind="inputProps"
          v-model="form.last"
          class="form-control w-full"
          type="text"
          autocomplete="family-name"
          :maxlength="LIMITS.name"
          :disabled="saveState.busy.value"
        >
      </FormField>
    </div>
    <FormField
      id="account-email"
      v-slot="{ inputProps }"
      label="Email"
      hint="Changing your email address also requires your current password."
      :errors="errors.email ? [errors.email] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.email"
        class="form-control w-full"
        type="email"
        autocomplete="email"
        :maxlength="LIMITS.email"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <FormField
      v-if="emailChanged"
      id="account-current-password"
      v-slot="{ inputProps }"
      label="Current password"
      hint="Required to change your email address."
      :errors="errors.currentPassword ? [errors.currentPassword] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.currentPassword"
        class="form-control w-full"
        type="password"
        autocomplete="current-password"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <FormField
      id="account-phone"
      v-slot="{ inputProps }"
      label="Phone"
    >
      <input
        v-bind="inputProps"
        v-model="form.phone"
        class="form-control w-full"
        type="tel"
        autocomplete="tel"
        :disabled="saveState.busy.value"
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
    </BaseAlert>
    <SubmitButton
      :busy="saveState.busy.value"
      busy-label="Saving account information"
    >
      Save account information
    </SubmitButton>
  </form>
</template>
