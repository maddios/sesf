<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { ApiError } from '../api/client';
import { forgotPassword, toFormErrors, type FormErrors } from '../api/auth';
import ResponseBanner from '../components/ResponseBanner.vue';
import FormField from '../components/FormField.vue';
import SubmitButton from '../components/SubmitButton.vue';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const GENERIC_SUCCESS = 'If an account exists for that email, we sent a link to reset your password. Check your inbox and spam folder.';

const email = ref('');
const errors = ref<FormErrors>({ messages: [], fields: {} });
const busy = ref(false);
const sent = ref(false);

async function submit(): Promise<void> {
  const trimmed = email.value.trim();
  if (!EMAIL_PATTERN.test(trimmed)) {
    errors.value = { messages: [], fields: { email: ['Enter a valid email address.'] } };
    return;
  }

  busy.value = true;
  errors.value = { messages: [], fields: {} };
  try {
    await forgotPassword(trimmed);
    // Same message whether or not the account exists, so the form cannot be used to probe for accounts.
    sent.value = true;
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      errors.value = toFormErrors(error, 'Check the email address and try again.');
    } else {
      errors.value = toFormErrors(error, 'We could not send the reset email. Try again.');
    }
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section
    class="max-w-lg"
    aria-labelledby="forgot-heading"
  >
    <h1
      id="forgot-heading"
      class="section-heading"
    >
      Password request
    </h1>

    <div
      v-if="sent"
      class="mt-2 space-y-4"
    >
      <ResponseBanner
        :messages="GENERIC_SUCCESS"
        variant="success"
      />
      <RouterLink
        to="/login"
        class="inline-block text-sm"
      >
        Back to sign in
      </RouterLink>
    </div>

    <form
      v-else
      class="mt-2 space-y-4"
      novalidate
      @submit.prevent="submit"
    >
      <p class="text-[0.9375rem]">
        Enter the email address on your account and we will send you a link to choose a new password.
      </p>
      <ResponseBanner :messages="errors.messages" />

      <FormField
        id="forgot-email"
        label="Email"
        :errors="errors.fields.email"
      >
        <template #default="{ inputProps }">
          <input
            v-bind="inputProps"
            v-model="email"
            type="email"
            class="form-control"
            placeholder="your@email.com"
            autocomplete="email"
            autocapitalize="none"
            spellcheck="false"
            aria-required="true"
            :disabled="busy"
          >
        </template>
      </FormField>

      <SubmitButton
        :busy="busy"
        busy-label="Sending"
      >
        Request password
      </SubmitButton>
    </form>
  </section>
</template>
