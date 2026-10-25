<script setup lang="ts">
/**
 * "Contact Us" / comments and bugs dialog. Anonymous visitors give a name and email; signed-in members send
 * only a subject and message (the server knows who they are). Success and failure are shown as banners
 * inside the dialog. Focus returns to the opener when the dialog closes (BaseModal).
 */
import { computed, reactive, ref, watch } from 'vue';
import { toFormErrors } from '../../api/auth';
import { sendReport } from '../../api/contact';
import { useAuthStore } from '../../stores/auth';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import { CONTACT_LIMITS, validateContact, type ContactErrors, type ContactValues } from './contactValidation';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const auth = useAuthStore();
const anonymous = computed(() => !auth.isLoggedIn);

const values = reactive<ContactValues>({ name: '', email: '', subject: '', message: '' });
const attempted = ref(false);
const busy = ref(false);
const status = ref<{ variant: 'success' | 'error'; message: string } | null>(null);

const problems = computed<ContactErrors>(() => validateContact(values, anonymous.value));

function errorFor(field: keyof ContactValues): string[] {
  const local = problems.value[field];
  return attempted.value && local !== undefined ? [local] : [];
}

/** Reset the form each time the dialog opens, so a previous message is not left behind. */
watch(
  () => props.open,
  (open, wasOpen) => {
    if (open && !wasOpen) {
      attempted.value = false;
      status.value = null;
    }
  },
);

function close(): void {
  emit('update:open', false);
}

async function submit(): Promise<void> {
  if (busy.value) {
    return;
  }
  attempted.value = true;
  status.value = null;
  if (Object.keys(problems.value).length > 0) {
    return;
  }

  busy.value = true;
  try {
    await sendReport({
      ...(anonymous.value ? { name: values.name.trim(), email: values.email.trim() } : {}),
      subject: values.subject.trim(),
      message: values.message.trim(),
    });
    values.subject = '';
    values.message = '';
    attempted.value = false;
    status.value = { variant: 'success', message: 'Thank you. Your message was sent.' };
  } catch (error) {
    const generic = 'Your message could not be sent. Please try again.';
    status.value = { variant: 'error', message: toFormErrors(error, generic).messages[0] ?? generic };
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    title="Contact us"
    size="md"
    close-label="Close contact form"
    @update:open="emit('update:open', $event)"
  >
    <form
      id="contact-form"
      class="space-y-4"
      novalidate
      @submit.prevent="submit"
    >
      <p class="text-sm text-muted">
        Please leave your comments below. If you are reporting a website bug or error, please be as detailed as possible.
      </p>

      <ResponseBanner
        v-if="status?.variant === 'error'"
        :messages="status.message"
      />
      <ResponseBanner
        v-else-if="status?.variant === 'success'"
        variant="success"
        :messages="status.message"
      />

      <template v-if="anonymous">
        <FormField
          id="contact-name"
          label="Your name"
          :errors="errorFor('name')"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="values.name"
              type="text"
              class="form-control"
              autocomplete="name"
              :maxlength="CONTACT_LIMITS.name"
              aria-required="true"
            >
          </template>
        </FormField>
        <FormField
          id="contact-email"
          label="Email"
          :errors="errorFor('email')"
        >
          <template #default="{ inputProps }">
            <input
              v-bind="inputProps"
              v-model="values.email"
              type="email"
              class="form-control"
              autocomplete="email"
              autocapitalize="none"
              spellcheck="false"
              :maxlength="CONTACT_LIMITS.email"
              aria-required="true"
            >
          </template>
        </FormField>
      </template>

      <FormField
        id="contact-subject"
        label="Subject"
        :errors="errorFor('subject')"
      >
        <template #default="{ inputProps }">
          <input
            v-bind="inputProps"
            v-model="values.subject"
            type="text"
            class="form-control"
            :maxlength="CONTACT_LIMITS.subject"
            aria-required="true"
          >
        </template>
      </FormField>

      <FormField
        id="contact-message"
        label="Message"
        :hint="`${values.message.length} of ${CONTACT_LIMITS.message} characters`"
        :errors="errorFor('message')"
      >
        <template #default="{ inputProps }">
          <textarea
            v-bind="inputProps"
            v-model="values.message"
            rows="7"
            class="form-control"
            :maxlength="CONTACT_LIMITS.message"
            aria-required="true"
          />
        </template>
      </FormField>

      <div class="flex flex-wrap justify-end gap-2 pt-2">
        <BaseButton @click="close">
          Cancel
        </BaseButton>
        <SubmitButton
          :busy="busy"
          busy-label="Sending"
        >
          Send
        </SubmitButton>
      </div>
    </form>
  </BaseModal>
</template>
