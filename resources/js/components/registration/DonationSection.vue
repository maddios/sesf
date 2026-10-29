<script setup lang="ts">
import FormField from '../FormField.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { useFormValues } from './formContext';
import { DONATION_OPTIONS } from '../../api/registration';

const { step = 6 } = defineProps<{
  step?: number;
  readOnly: boolean;
  fieldError: (path: string) => string | undefined;
  messages: string[];
  domId: string;
}>();

function donationLabel(amount: number): string {
  return amount === 0 ? 'No thanks!' : `$${amount}`;
}

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Donation"
    :step="step"
    :messages="messages"
  >
    <p class="text-sm">
      We keep registration costs as low as possible. Donations go back into extra meals, events and other benefits for
      everyone. Nothing is required, and anything is appreciated.
    </p>
    <FormField
      id="donation"
      v-slot="{ inputProps }"
      label="Donation amount"
      :errors="messageList(fieldError('donation'))"
    >
      <select
        v-bind="inputProps"
        class="form-control max-w-xs"
        :value="form.donation"
        :disabled="readOnly"
        @change="form.donation = Number(($event.target as HTMLSelectElement).value)"
      >
        <option
          v-for="amount in DONATION_OPTIONS"
          :key="amount"
          :value="amount"
        >
          {{ donationLabel(amount) }}
        </option>
      </select>
    </FormField>
  </SectionShell>
</template>
