<script setup lang="ts">
import FormField from '../FormField.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { useFormValues } from './formContext';
import { ARRIVAL_TIMES } from '../../api/registration';
import type { EventShowDate } from '../../api/events';

defineProps<{
  eventDates: EventShowDate[];
  readOnly: boolean;
  fieldError: (path: string) => string | undefined;
  touch: (path: string) => void;
  messages: string[];
  domId: string;
}>();

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Approximate arrival"
    :step="5"
    hint="A rough estimate helps with planning. You can choose a day and a time of day."
    :messages="messages"
  >
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField
        id="arrival-date"
        v-slot="{ inputProps }"
        label="Day"
        :errors="messageList(fieldError('arrival.date'))"
      >
        <select
          v-bind="inputProps"
          class="form-control"
          :value="form.arrivalDate"
          :disabled="readOnly"
          @change="form.arrivalDate = ($event.target as HTMLSelectElement).value"
          @blur="touch('arrival.date')"
        >
          <option value="">
            Please select
          </option>
          <option
            v-for="day in eventDates"
            :key="day.date"
            :value="day.date"
          >
            {{ day.name || day.date }}
          </option>
        </select>
      </FormField>
      <FormField
        id="arrival-time"
        v-slot="{ inputProps }"
        label="Time"
        :errors="messageList(fieldError('arrival.time'))"
      >
        <select
          v-bind="inputProps"
          class="form-control"
          :value="form.arrivalTime"
          :disabled="readOnly"
          @change="form.arrivalTime = ($event.target as HTMLSelectElement).value"
          @blur="touch('arrival.time')"
        >
          <option value="">
            Please select
          </option>
          <option
            v-for="time in ARRIVAL_TIMES"
            :key="time.value"
            :value="time.value"
          >
            {{ time.label }}
          </option>
        </select>
      </FormField>
    </div>
  </SectionShell>
</template>
