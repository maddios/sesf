<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { ApiError } from '../../api/client';
import { toFormErrors, type FormErrors } from '../../api/auth';
import { registerAccount } from '../../api/register';
import { useAuthStore } from '../../stores/auth';
import { applyPostLoginRedirect, resolvePostLoginRedirect } from '../../router/postLogin';
import { getTurnstileSiteKey } from '../../config';
import FormField from '../../components/FormField.vue';
import ResponseBanner from '../../components/ResponseBanner.vue';
import SubmitButton from '../../components/SubmitButton.vue';
import TurnstileWidget from '../../components/register/TurnstileWidget.vue';
import {
  FIELD_LIMITS,
  FIELD_ORDER,
  PASSWORD_MIN_LENGTH,
  formatPhone,
  mapServerMessages,
  validateRegister,
  type RegisterErrors,
  type RegisterField,
  type RegisterValues,
} from '../../components/register/registerValidation';

const EMPTY_FORM_ERRORS: FormErrors = { messages: [], fields: {} };

const router = useRouter();
const auth = useAuthStore();
const turnstileSiteKey = getTurnstileSiteKey();
const verificationConfigured = turnstileSiteKey !== '';

const values = reactive<RegisterValues>({
  first_name: '',
  last_name: '',
  email: '',
  email_confirmation: '',
  street: '',
  street2: '',
  city: '',
  state: '',
  zip: '',
  phone: '',
  password: '',
  password_confirmation: '',
  accept_privacy_policy: false,
});

const token = ref<string | null>(null);
const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null);
const busy = ref(false);
const attempted = ref(false);
const touched = reactive<Partial<Record<RegisterField, boolean>>>({});
/** Errors from the server, keyed by field. Cleared for a field as soon as that field changes. */
const serverFields = ref<RegisterErrors>({});
const formErrors = ref<FormErrors>(EMPTY_FORM_ERRORS);

const liveErrors = computed<RegisterErrors>(() => validateRegister(values, token.value !== null));

/** A field error is shown after the user has left the field or tried to submit; server errors always show. */
function errorFor(field: RegisterField): string[] {
  const server = serverFields.value[field];
  if (server !== undefined) {
    return [server];
  }
  const local = liveErrors.value[field];
  return local !== undefined && (attempted.value || touched[field] === true) ? [local] : [];
}

function markTouched(field: RegisterField): void {
  touched[field] = true;
}

function changed(field: RegisterField): void {
  if (serverFields.value[field] !== undefined) {
    const next = { ...serverFields.value };
    delete next[field];
    serverFields.value = next;
  }
}

function onPhoneInput(event: Event): void {
  const input = event.target as HTMLInputElement;
  const formatted = formatPhone(input.value);
  input.value = formatted;
  values.phone = formatted;
  changed('phone');
}

function resetVerification(): void {
  token.value = null;
  turnstile.value?.reset();
}

async function focusFirstInvalid(problems: RegisterErrors): Promise<void> {
  const field = FIELD_ORDER.find((name) => problems[name] !== undefined);
  if (field === undefined) {
    return;
  }
  await nextTick();
  const target = document.getElementById(`reg-${field.replace(/_/g, '-')}`);
  if (target instanceof HTMLElement) {
    target.focus();
  }
}

/** Maps a failed registration to the banner and to field errors. Legacy 400 bodies are message arrays read by wording. */
function applyServerError(error: unknown): void {
  const converted = toFormErrors(error, 'We could not create your account. Try again.');
  const fields: RegisterErrors = {};
  for (const [name, messages] of Object.entries(converted.fields)) {
    const known = FIELD_ORDER.find((field) => field === name);
    if (known !== undefined) {
      fields[known] = messages[0];
    }
  }
  let banner = converted.messages;
  if (error instanceof ApiError && error.status === 400) {
    const mapped = mapServerMessages(converted.messages);
    Object.assign(fields, mapped.fields);
    banner = mapped.banner;
  }
  serverFields.value = fields;
  formErrors.value = { messages: banner, fields: {} };
}

async function submit(): Promise<void> {
  if (busy.value || !verificationConfigured) {
    return;
  }
  attempted.value = true;
  serverFields.value = {};
  formErrors.value = EMPTY_FORM_ERRORS;

  const problems = liveErrors.value;
  if (Object.keys(problems).length > 0) {
    await focusFirstInvalid(problems);
    return;
  }

  busy.value = true;
  let succeeded = false;
  try {
    const payload = await registerAccount({
      first_name: values.first_name.trim(),
      last_name: values.last_name.trim(),
      email: values.email.trim(),
      email_confirmation: values.email_confirmation.trim(),
      street: values.street.trim(),
      street2: values.street2.trim(),
      city: values.city.trim(),
      state: values.state.trim(),
      zip: values.zip.trim(),
      phone: values.phone.trim(),
      password: values.password,
      password_confirmation: values.password_confirmation,
      accept_privacy_policy: values.accept_privacy_policy,
      cf_turnstile_token: token.value ?? '',
    });
    auth.applyPayload(payload);
    succeeded = true;
    // Legacy sent the new member to their profile to add a photo and vehicles.
    // The server sends profileURL as a hash link ("#/NoraS/u12"); the redirect rules only accept "/..." paths, so
    // without dropping the "#" every new member landed on the home page (found by E2E, docs/e2e.md).
    await applyPostLoginRedirect(router, resolvePostLoginRedirect(payload.profileURL.replace(/^#/, '')));
  } catch (error) {
    // Turnstile tokens are single-use: every failed attempt gets a fresh widget.
    resetVerification();
    applyServerError(error);
    await nextTick();
    if (formErrors.value.messages.length > 0) {
      document.getElementById('reg-form-status')?.focus();
    } else {
      await focusFirstInvalid(serverFields.value);
    }
  } finally {
    if (!succeeded) {
      busy.value = false;
    }
  }
}

</script>

<template>
  <section aria-labelledby="register-heading">
    <h1
      id="register-heading"
      class="page-title"
    >
      Create SESF Account
    </h1>
    <p class="mt-2 font-semibold">
      You must create an account to sign up for events, interact with other Southeast Sharkfest members and receive event updates.
    </p>

    <form
      class="mt-6 space-y-8"
      novalidate
      @submit.prevent="submit"
    >
      <div
        id="reg-form-status"
        tabindex="-1"
        class="space-y-4"
      >
        <ResponseBanner :messages="formErrors.messages" />
      </div>

      <fieldset class="space-y-4">
        <legend class="section-heading w-full">
          User information
        </legend>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="reg-first-name"
            label="First name"
            :errors="errorFor('first_name')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.first_name"
                type="text"
                class="form-control"
                autocomplete="given-name"
                :maxlength="FIELD_LIMITS.first_name"
                aria-required="true"
                @input="changed('first_name')"
                @blur="markTouched('first_name')"
              >
            </template>
          </FormField>
          <FormField
            id="reg-last-name"
            label="Last name"
            :errors="errorFor('last_name')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.last_name"
                type="text"
                class="form-control"
                autocomplete="family-name"
                :maxlength="FIELD_LIMITS.last_name"
                aria-required="true"
                @input="changed('last_name')"
                @blur="markTouched('last_name')"
              >
            </template>
          </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="reg-email"
            label="Email"
            :errors="errorFor('email')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.email"
                type="email"
                class="form-control"
                placeholder="your@email.com"
                autocomplete="email"
                autocapitalize="none"
                spellcheck="false"
                :maxlength="FIELD_LIMITS.email"
                aria-required="true"
                @input="changed('email')"
                @blur="markTouched('email')"
              >
            </template>
          </FormField>
          <FormField
            id="reg-email-confirmation"
            label="Confirm email"
            :errors="errorFor('email_confirmation')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.email_confirmation"
                type="email"
                class="form-control"
                placeholder="your@email.com"
                autocomplete="email"
                autocapitalize="none"
                spellcheck="false"
                :maxlength="FIELD_LIMITS.email_confirmation"
                aria-required="true"
                @input="changed('email_confirmation')"
                @blur="markTouched('email_confirmation')"
              >
            </template>
          </FormField>
        </div>
      </fieldset>

      <fieldset class="space-y-4">
        <legend class="section-heading w-full">
          Address
        </legend>
        <FormField
          id="reg-street"
          label="Street"
          :errors="errorFor('street')"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="values.street"
              type="text"
              class="form-control"
              autocomplete="street-address"
              :maxlength="FIELD_LIMITS.street"
              aria-required="true"
              @input="changed('street')"
              @blur="markTouched('street')"
            >
          </template>
        </FormField>
        <FormField
          id="reg-street2"
          label="Street (line 2)"
          :errors="errorFor('street2')"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="values.street2"
              type="text"
              class="form-control"
              autocomplete="address-line2"
              :maxlength="FIELD_LIMITS.street2"
              @input="changed('street2')"
              @blur="markTouched('street2')"
            >
          </template>
        </FormField>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="reg-city"
            label="City"
            :errors="errorFor('city')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.city"
                type="text"
                class="form-control"
                autocomplete="address-level2"
                :maxlength="FIELD_LIMITS.city"
                aria-required="true"
                @input="changed('city')"
                @blur="markTouched('city')"
              >
            </template>
          </FormField>
          <FormField
            id="reg-state"
            label="State"
            :errors="errorFor('state')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.state"
                type="text"
                class="form-control"
                autocomplete="address-level1"
                :maxlength="FIELD_LIMITS.state"
                aria-required="true"
                @input="changed('state')"
                @blur="markTouched('state')"
              >
            </template>
          </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="reg-zip"
            label="ZIP code"
            :errors="errorFor('zip')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.zip"
                type="text"
                class="form-control"
                placeholder="12345"
                autocomplete="postal-code"
                inputmode="numeric"
                :maxlength="FIELD_LIMITS.zip"
                aria-required="true"
                @input="changed('zip')"
                @blur="markTouched('zip')"
              >
            </template>
          </FormField>
          <FormField
            id="reg-phone"
            label="Phone number"
            hint="Format: 123-123-1234"
            :errors="errorFor('phone')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                :value="values.phone"
                type="tel"
                class="form-control"
                placeholder="123-123-1234"
                autocomplete="tel"
                inputmode="tel"
                :maxlength="FIELD_LIMITS.phone"
                aria-required="true"
                @input="onPhoneInput"
                @blur="markTouched('phone')"
              >
            </template>
          </FormField>
        </div>
      </fieldset>

      <fieldset class="space-y-4">
        <legend class="section-heading w-full">
          Password
        </legend>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="reg-password"
            label="Password"
            :hint="`At least ${PASSWORD_MIN_LENGTH} characters.`"
            :errors="errorFor('password')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.password"
                type="password"
                class="form-control"
                autocomplete="new-password"
                aria-required="true"
                @input="changed('password')"
                @blur="markTouched('password')"
              >
            </template>
          </FormField>
          <FormField
            id="reg-password-confirmation"
            label="Confirm password"
            :errors="errorFor('password_confirmation')"
          >
            <template #default="{ inputProps }">
              <input
                v-bind="inputProps"
                v-model="values.password_confirmation"
                type="password"
                class="form-control"
                autocomplete="new-password"
                aria-required="true"
                @input="changed('password_confirmation')"
                @blur="markTouched('password_confirmation')"
              >
            </template>
          </FormField>
        </div>
      </fieldset>

      <fieldset class="space-y-4">
        <legend class="section-heading w-full">
          Security check
        </legend>
        <ResponseBanner
          v-if="!verificationConfigured"
          variant="warning"
          :messages="'Development notice: the verification service is not configured (no Turnstile site key). Creating an account is disabled until it is set.'"
        />
        <template v-else>
          <TurnstileWidget
            ref="turnstile"
            :site-key="turnstileSiteKey"
            @update:token="token = $event; changed('cf_turnstile_token')"
          />
          <p
            v-if="errorFor('cf_turnstile_token').length > 0"
            id="reg-cf-turnstile-token-error"
            class="text-sm text-danger-fg"
          >
            {{ errorFor('cf_turnstile_token')[0] }}
          </p>
        </template>
      </fieldset>

      <fieldset class="space-y-2">
        <div class="flex items-start gap-2">
          <input
            id="reg-privacy"
            v-model="values.accept_privacy_policy"
            type="checkbox"
            class="mt-1 h-4 w-4 rounded-sm border-border-strong accent-brand"
            :aria-invalid="errorFor('accept_privacy_policy').length > 0"
            :aria-describedby="errorFor('accept_privacy_policy').length > 0 ? 'reg-privacy-error' : undefined"
            aria-required="true"
            @change="changed('accept_privacy_policy')"
          >
          <label
            for="reg-privacy"
            class="text-sm text-text"
          >
            I consent to all terms and conditions found on this website, including the
            <RouterLink to="/privacy-policy">Privacy Policy</RouterLink>.
          </label>
        </div>
        <p
          v-if="errorFor('accept_privacy_policy').length > 0"
          id="reg-privacy-error"
          class="text-sm text-danger-fg"
        >
          {{ errorFor('accept_privacy_policy')[0] }}
        </p>
      </fieldset>

      <div>
        <SubmitButton
          :busy="busy"
          busy-label="Creating account"
          :disabled="!verificationConfigured"
        >
          Create Account
        </SubmitButton>
      </div>
    </form>
  </section>
</template>
