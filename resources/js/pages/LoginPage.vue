<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError } from '../api/client';
import { requestEmailSignin, toFormErrors, type FormErrors } from '../api/auth';
import { useAuthStore } from '../stores/auth';
import { applyPostLoginRedirect, firstQueryString, resolvePostLoginRedirect } from '../router/postLogin';
import { SIGNIN_CODE_LENGTH, sanitizeCode } from './codeInput';
import ResponseBanner from '../components/ResponseBanner.vue';
import FormField from '../components/FormField.vue';
import SubmitButton from '../components/SubmitButton.vue';

type Tab = 'password' | 'code';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const CODE_LENGTH = SIGNIN_CODE_LENGTH;
const EMPTY_ERRORS: FormErrors = { messages: [], fields: {} };

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const tab = ref<Tab>('password');
/** Hidden once the server reports emailed sign-in is unavailable (503). */
const codeTabAvailable = ref(true);
const emailSigninExpired = computed(() => firstQueryString(route.query.email_signin) === 'invalid');

// Password form
const email = ref('');
const password = ref('');
const remember = ref(false);
const passwordErrors = ref<FormErrors>(EMPTY_ERRORS);
const passwordBusy = ref(false);

// Emailed code form
const codeStep = ref<'request' | 'verify'>('request');
const codeEmail = ref('');
const code = ref('');
const codeNotice = ref<string | null>(null);
const codeErrors = ref<FormErrors>(EMPTY_ERRORS);
const codeBusy = ref(false);

/** The ?redirect= the guard or a link set, or the remembered destination. */
function redirectTarget(): string | undefined {
  return firstQueryString(route.query.redirect) ?? auth.consumeNextUrl() ?? undefined;
}

async function finishLogin(serverRedirect: string | null | undefined): Promise<void> {
  await applyPostLoginRedirect(router, resolvePostLoginRedirect(serverRedirect ?? redirectTarget()));
}

function fieldErrors(fields: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(fields).map(([name, message]) => [name, [message]]));
}

async function submitPassword(): Promise<void> {
  const trimmedEmail = email.value.trim();
  const problems: Record<string, string> = {};
  if (!EMAIL_PATTERN.test(trimmedEmail)) {
    problems.email = 'Enter a valid email address.';
  }
  if (password.value === '') {
    problems.password = 'Enter your password.';
  }
  if (Object.keys(problems).length > 0) {
    passwordErrors.value = { messages: [], fields: fieldErrors(problems) };
    return;
  }

  passwordBusy.value = true;
  passwordErrors.value = EMPTY_ERRORS;
  try {
    await auth.login({ email: trimmedEmail, password: password.value, remember: remember.value });
    await finishLogin(undefined);
  } catch (error) {
    passwordErrors.value = toFormErrors(error, 'Sign-in failed. Check your email and password and try again.');
    password.value = '';
  } finally {
    passwordBusy.value = false;
  }
}

async function sendCode(): Promise<void> {
  const requestEmail = codeEmail.value.trim();
  if (!EMAIL_PATTERN.test(requestEmail)) {
    codeErrors.value = { messages: [], fields: { email: ['Enter a valid email address.'] } };
    return;
  }

  codeBusy.value = true;
  codeErrors.value = EMPTY_ERRORS;
  codeNotice.value = null;
  const redirectPath = firstQueryString(route.query.redirect);
  try {
    await requestEmailSignin({
      email: requestEmail,
      ...(redirectPath === undefined ? {} : { redirect_path: redirectPath }),
    });
    codeEmail.value = requestEmail;
    code.value = '';
    codeStep.value = 'verify';
    codeNotice.value = 'Check your email. We sent you a code.';
  } catch (error) {
    if (error instanceof ApiError && error.status === 503) {
      // Emailed sign-in is switched off: drop the tab and explain on the password form.
      codeTabAvailable.value = false;
      tab.value = 'password';
      passwordErrors.value = toFormErrors(error, 'Emailed sign-in is not available right now. Sign in with your password.');
    } else {
      codeErrors.value = toFormErrors(error, 'We could not send a code. Try again.');
    }
  } finally {
    codeBusy.value = false;
  }
}

async function verifyCode(): Promise<void> {
  if (codeBusy.value) {
    return;
  }
  if (code.value.length !== CODE_LENGTH) {
    codeErrors.value = { messages: [], fields: { code: [`Enter the ${CODE_LENGTH}-digit code from the email.`] } };
    return;
  }

  codeBusy.value = true;
  codeErrors.value = EMPTY_ERRORS;
  try {
    const serverRedirect = await auth.loginWithEmailCode(codeEmail.value, code.value);
    await finishLogin(serverRedirect);
  } catch (error) {
    codeErrors.value = toFormErrors(error, 'That code is not valid or has expired. Request a new one.');
    code.value = '';
  } finally {
    codeBusy.value = false;
  }
}

function submitCodeForm(): void {
  if (codeStep.value === 'request') {
    void sendCode();
  } else {
    void verifyCode();
  }
}

/** Keeps digits only, caps at six, and submits as soon as the sixth digit is in. */
function onCodeInput(event: { target: unknown }): void {
  const input = event.target as { value: string } | null;
  if (input === null) {
    return;
  }
  const digits = sanitizeCode(input.value);
  input.value = digits;
  code.value = digits;
  if (digits.length === CODE_LENGTH) {
    void verifyCode();
  }
}

function restartCodeSignin(): void {
  codeStep.value = 'request';
  code.value = '';
  codeErrors.value = EMPTY_ERRORS;
  codeNotice.value = null;
}

function selectTab(next: Tab): void {
  tab.value = next;
  if (next === 'password') {
    codeErrors.value = EMPTY_ERRORS;
  }
}
</script>

<template>
  <section
    class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
    aria-labelledby="login-heading"
  >
    <h1
      id="login-heading"
      class="text-2xl font-bold"
    >
      Sign in
    </h1>

    <div class="mt-4">
      <ResponseBanner
        v-if="emailSigninExpired"
        variant="warning"
        :messages="'This sign-in link is no longer valid. Use “Email me a code” to request a new one.'"
      />
    </div>

    <div
      role="tablist"
      aria-label="Sign-in method"
      class="mt-4 flex gap-2 border-b border-slate-200"
    >
      <button
        id="tab-password"
        type="button"
        role="tab"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-blue-800"
        :class="tab === 'password' ? 'border-blue-800 text-blue-900' : 'border-transparent text-slate-600 hover:text-slate-900'"
        :aria-selected="tab === 'password' ? 'true' : 'false'"
        @click="selectTab('password')"
      >
        Password
      </button>
      <button
        v-if="codeTabAvailable"
        id="tab-code"
        type="button"
        role="tab"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-blue-800"
        :class="tab === 'code' ? 'border-blue-800 text-blue-900' : 'border-transparent text-slate-600 hover:text-slate-900'"
        :aria-selected="tab === 'code' ? 'true' : 'false'"
        @click="selectTab('code')"
      >
        Email me a code
      </button>
    </div>

    <div
      v-if="tab === 'password'"
      id="panel-password"
      role="tabpanel"
      aria-labelledby="tab-password"
      class="mt-6"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitPassword"
      >
        <ResponseBanner :messages="passwordErrors.messages" />

        <FormField
          id="login-email"
          label="Email"
          :errors="passwordErrors.fields.email"
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
            >
          </template>
        </FormField>

        <FormField
          id="login-password"
          label="Password"
          :errors="passwordErrors.fields.password"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="password"
              type="password"
              class="w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-blue-800"
              placeholder="Password"
              autocomplete="current-password"
              aria-required="true"
            >
          </template>
        </FormField>

        <div class="flex items-center gap-2">
          <input
            id="login-remember"
            v-model="remember"
            type="checkbox"
            class="h-4 w-4 rounded border-slate-300"
          >
          <label
            for="login-remember"
            class="text-sm text-slate-800"
          >Remember me on this device</label>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <SubmitButton
            :busy="passwordBusy"
            busy-label="Signing in"
          >
            Sign in
          </SubmitButton>
          <RouterLink
            to="/forgot-password"
            class="text-sm font-medium text-blue-800 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-blue-800"
          >
            Forgot password?
          </RouterLink>
        </div>
      </form>
    </div>

    <div
      v-else
      id="panel-code"
      role="tabpanel"
      aria-labelledby="tab-code"
      class="mt-6 space-y-4"
    >
      <form
        v-if="codeStep === 'request'"
        class="space-y-4"
        novalidate
        @submit.prevent="submitCodeForm"
      >
        <p class="text-sm text-slate-700">
          We will email you a 6-digit code. No password needed.
        </p>
        <ResponseBanner :messages="codeErrors.messages" />
        <FormField
          id="code-email"
          label="Email"
          :errors="codeErrors.fields.email"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="codeEmail"
              type="email"
              class="w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-blue-800"
              placeholder="your@email.com"
              autocomplete="username"
              autocapitalize="none"
              spellcheck="false"
              aria-required="true"
              :disabled="codeBusy"
            >
          </template>
        </FormField>
        <SubmitButton
          :busy="codeBusy"
          busy-label="Sending"
        >
          Email me a sign-in code
        </SubmitButton>
      </form>

      <form
        v-else
        class="space-y-4"
        novalidate
        @submit.prevent="submitCodeForm"
      >
        <p class="text-sm text-slate-700">
          Open the email we just sent and type the 6 numbers below.
        </p>
        <ResponseBanner
          :messages="codeNotice"
          variant="success"
        />
        <ResponseBanner :messages="codeErrors.messages" />
        <FormField
          id="code-value"
          label="Your code"
          :errors="codeErrors.fields.code"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              :value="code"
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="6"
              class="w-40 rounded-md border border-slate-300 px-3 py-2 font-mono text-lg tracking-widest focus-visible:outline-2 focus-visible:outline-blue-800"
              placeholder="000000"
              autocomplete="one-time-code"
              aria-required="true"
              :disabled="codeBusy"
              @input="onCodeInput"
            >
          </template>
        </FormField>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <SubmitButton
            :busy="codeBusy"
            busy-label="Checking"
          >
            Continue
          </SubmitButton>
          <button
            type="button"
            class="text-sm font-medium text-blue-800 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-blue-800"
            @click="restartCodeSignin"
          >
            Use a different email
          </button>
        </div>
      </form>
    </div>

    <div class="mt-8 border-t border-slate-200 pt-4 text-sm text-slate-700">
      New to SESF?
      <a
        href="#/register"
        class="font-medium text-blue-800 underline underline-offset-2 hover:text-blue-900 focus-visible:outline-2 focus-visible:outline-blue-800"
      >Create an account</a>
      to register for events and manage your cars.
    </div>
  </section>
</template>
