<script setup lang="ts">
/** Password change. A successful change clears all three fields; a failed one keeps them for correction. */
import { reactive } from 'vue';
import { changePassword, type PasswordInput } from '../../api/account';
import { useSaveState } from '../../composables/account/useSaveState';
import { hasErrors, LIMITS, validatePassword, type FieldErrors } from '../../composables/account/validation';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import BaseAlert from '../ui/BaseAlert.vue';

const form = reactive<PasswordInput>({ oldPassword: '', newPassword: '', verifyPassword: '' });
const errors = reactive<FieldErrors<keyof PasswordInput>>({});
const saveState = useSaveState();

function setErrors(next: FieldErrors<keyof PasswordInput>): void {
  for (const key of Object.keys(errors) as (keyof PasswordInput)[]) {
    delete errors[key];
  }
  Object.assign(errors, next);
}

async function onSubmit(): Promise<void> {
  saveState.reset();
  const next = validatePassword(form);
  setErrors(next);
  if (hasErrors(next)) {
    return;
  }
  const input: PasswordInput = { ...form };
  const ok = await saveState.run(() => changePassword(input), 'Your password was changed.');
  if (ok) {
    form.oldPassword = '';
    form.newPassword = '';
    form.verifyPassword = '';
  }
}
</script>

<template>
  <form
    class="space-y-4"
    novalidate
    @submit.prevent="onSubmit"
  >
    <FormField
      id="password-old"
      v-slot="{ inputProps }"
      label="Current password"
      :errors="errors.oldPassword ? [errors.oldPassword] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.oldPassword"
        class="form-control w-full"
        type="password"
        autocomplete="current-password"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <FormField
      id="password-new"
      v-slot="{ inputProps }"
      label="New password"
      :hint="`At least ${LIMITS.password} characters.`"
      :errors="errors.newPassword ? [errors.newPassword] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.newPassword"
        class="form-control w-full"
        type="password"
        autocomplete="new-password"
        :disabled="saveState.busy.value"
      >
    </FormField>
    <FormField
      id="password-verify"
      v-slot="{ inputProps }"
      label="Repeat new password"
      :errors="errors.verifyPassword ? [errors.verifyPassword] : []"
    >
      <input
        v-bind="inputProps"
        v-model="form.verifyPassword"
        class="form-control w-full"
        type="password"
        autocomplete="new-password"
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
      busy-label="Changing password"
    >
      Change password
    </SubmitButton>
  </form>
</template>
