<script setup lang="ts">
/**
 * "Contact" dialog: sends a private message to a member (POST member/message). The server answers 401 for
 * opted-out or unknown recipients, so every failure shows the same generic message.
 */
import { ref, useId, watch } from 'vue';
import { sendMemberMessage } from '../../api/members';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';

const props = defineProps<{
  open: boolean;
  recipientId: number;
  recipientName: string;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const formId = useId();
const subject = ref('');
const message = ref('');
const sending = ref(false);
const sent = ref(false);
const failed = ref(false);
const validation = ref('');

watch(
  () => props.open,
  (open) => {
    if (!open) {
      sent.value = false;
      failed.value = false;
      validation.value = '';
    }
  },
);

async function submit(): Promise<void> {
  failed.value = false;
  sent.value = false;
  if (subject.value.trim() === '' || message.value.trim() === '') {
    validation.value = 'Enter a subject and a message before sending.';
    return;
  }
  validation.value = '';
  sending.value = true;
  try {
    await sendMemberMessage(props.recipientId, subject.value.trim(), message.value.trim());
    sent.value = true;
    subject.value = '';
    message.value = '';
  } catch {
    failed.value = true;
  } finally {
    sending.value = false;
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="`Contact ${recipientName}`"
    @update:open="emit('update:open', $event)"
  >
    <form
      :id="formId"
      class="space-y-4"
      novalidate
      @submit.prevent="submit"
    >
      <BaseAlert
        v-if="sent"
        variant="success"
      >
        Your message was sent.
      </BaseAlert>
      <BaseAlert
        v-if="failed"
        variant="error"
      >
        Your message could not be sent. Please try again later.
      </BaseAlert>
      <BaseAlert
        v-if="validation"
        variant="warning"
      >
        {{ validation }}
      </BaseAlert>
      <div>
        <label
          :for="`${formId}-subject`"
          class="block text-sm font-semibold text-heading"
        >Subject</label>
        <input
          :id="`${formId}-subject`"
          v-model="subject"
          type="text"
          maxlength="150"
          class="mt-1 block w-full rounded-sm border border-border-strong bg-panel px-2 py-1.5 text-sm"
        >
      </div>
      <div>
        <label
          :for="`${formId}-message`"
          class="block text-sm font-semibold text-heading"
        >Message</label>
        <textarea
          :id="`${formId}-message`"
          v-model="message"
          rows="6"
          maxlength="5000"
          class="mt-1 block w-full rounded-sm border border-border-strong bg-panel px-2 py-1.5 text-sm"
        />
      </div>
    </form>
    <template #footer>
      <BaseButton
        type="submit"
        variant="primary"
        :form="formId"
        :loading="sending"
        loading-label="Sending message"
      >
        Send message
      </BaseButton>
    </template>
  </BaseModal>
</template>
