<script setup lang="ts">
import SectionShell from './SectionShell.vue';
import { useFormValues } from './formContext';

defineProps<{
  readOnly: boolean;
  registrationCloseDate: string;
  error?: string;
  touch: (path: string) => void;
  messages: string[];
  domId: string;
}>();

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Registration and cancellation policy"
    :step="7"
    :messages="messages"
  >
    <div class="space-y-3 text-sm">
      <p>
        Your registration is confirmed once the registration fee is received. Registrations that are not completed and
        paid are cancelled automatically at midnight on the registration cutoff date<template v-if="registrationCloseDate">
          ({{ registrationCloseDate }})
        </template>. Registering after the cutoff incurs a $30 late fee.
      </p>
      <p>
        The registration fee is fully refundable until the cutoff date at midnight. After that date, venue, food and
        beverage deposits are committed, so refunds are not available.
      </p>
    </div>
    <div
      class="rounded-sm border p-3"
      :class="error ? 'border-danger-border bg-danger-bg' : 'border-border'"
    >
      <div class="flex items-start gap-3">
        <input
          id="cancel-ack"
          type="checkbox"
          class="mt-1 h-4 w-4"
          :checked="form.cancelAck"
          :disabled="readOnly"
          :aria-invalid="error ? 'true' : undefined"
          :aria-describedby="error ? 'cancel-ack-error' : undefined"
          @change="form.cancelAck = ($event.target as HTMLInputElement).checked"
          @blur="touch('cancel')"
        >
        <label
          for="cancel-ack"
          class="text-sm font-medium"
        >
          I have read and understood the registration and cancellation policies.
        </label>
      </div>
      <p
        v-if="error"
        id="cancel-ack-error"
        class="mt-2 text-sm text-danger-fg"
      >
        {{ error }}
      </p>
    </div>
  </SectionShell>
</template>
