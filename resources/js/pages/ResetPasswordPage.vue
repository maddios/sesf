<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { resetPassword, toFormErrors, type FormErrors } from '../api/auth';
import { useAuthStore } from '../stores/auth';
import { firstQueryString } from '../router/postLogin';
import ResponseBanner from '../components/ResponseBanner.vue';
import FormField from '../components/FormField.vue';
import SubmitButton from '../components/SubmitButton.vue';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MIN_PASSWORD_LENGTH = 6;

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

/** Both come from the emailed link: /#/reset-password?token=...&email=... */
const token = computed(() => firstQueryString(route.query.token) ?? '');
const email = ref(firstQueryString(route.query.email) ?? '');
const password = ref('');
const passwordConfirm = ref('');
const errors = ref<FormErrors>({ messages: [], fields: {} });
const busy = ref(false);

async function submit(): Promise<void> {
  const trimmedEmail = email.value.trim();
  const problems: Record<string, string[]> = {};
  if (!EMAIL_PATTERN.test(trimmedEmail)) {
    problems.email = ['Enter a valid email address.'];
  }
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    problems.password = [`Password must be at least ${MIN_PASSWORD_LENGTH} characters long.`];
  }
  if (passwordConfirm.value !== password.value) {
    problems.password_confirmation = ['The passwords do not match.'];
  }
  if (Object.keys(problems).length > 0) {
    errors.value = { messages: [], fields: problems };
    return;
  }

  busy.value = true;
  errors.value = { messages: [], fields: {} };
  try {
    const payload = await resetPassword({
      token: token.value,
      email: trimmedEmail,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    });
    auth.applyPayload(payload);
    await router.push('/');
  } catch (error) {
    errors.value = toFormErrors(error, 'We could not reset your password. Request a new link and try again.');
    password.value = '';
    passwordConfirm.value = '';
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section
    class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
    aria-labelledby="reset-heading"
  >
    <h1
      id="reset-heading"
      class="text-2xl font-bold"
    >
      Password reset
    </h1>

    <div
      v-if="token === ''"
      class="mt-4 space-y-4"
    >
      <ResponseBanner :messages="'This reset link is incomplete. Request a new password reset email.'" />
      <RouterLink
        to="/forgot-password"
        class="inline-block text-sm font-medium text-blue-800 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-blue-800"
      >
        Request a new reset email
      </RouterLink>
    </div>

    <form
      v-else
      class="mt-4 space-y-4"
      novalidate
      @submit.prevent="submit"
    >
      <ResponseBanner :messages="[...errors.messages, ...(errors.fields.token ?? [])]" />

      <FormField
        id="reset-email"
        label="Email"
        :errors="errors.fields.email"
      >
        <template #default="{ inputProps }">
          <input
            v-bind="inputProps"
            v-model="email"
            type="email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-blue-800"
            placeholder="your@email.com"
            autocomplete="username"
            autocapitalize="none"
            spellcheck="false"
            aria-required="true"
            :disabled="busy"
          >
        </template>
      </FormField>

      <FormField
        id="reset-password"
        label="New password"
        hint="At least 6 characters."
        :errors="errors.fields.password"
      >
        <template #default="{ inputProps }">
          <input
            v-bind="inputProps"
            v-model="password"
            type="password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-blue-800"
            placeholder="New password"
            autocomplete="new-password"
            aria-required="true"
            :disabled="busy"
          >
        </template>
      </FormField>

      <FormField
        id="reset-password-confirm"
        label="Confirm new password"
        :errors="errors.fields.password_confirmation"
      >
        <template #default="{ inputProps }">
          <input
            v-bind="inputProps"
            v-model="passwordConfirm"
            type="password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-blue-800"
            placeholder="Confirm new password"
            autocomplete="new-password"
            aria-required="true"
            :disabled="busy"
          >
        </template>
      </FormField>

      <SubmitButton
        :busy="busy"
        busy-label="Resetting"
      >
        Reset password
      </SubmitButton>
    </form>
  </section>
</template>
