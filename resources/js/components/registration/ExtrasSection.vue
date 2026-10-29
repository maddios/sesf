<script setup lang="ts">
import FormField from '../FormField.vue';
import SectionShell from './SectionShell.vue';
import { useFormValues } from './formContext';
import { messageList } from './fieldErrors';
import { extraBounds,  } from '../../composables/registration/form';
import type { ShowExtra } from '../../api/registration';

defineProps<{
  extras: ShowExtra[];
  readOnly: boolean;
  fieldError: (path: string) => string | undefined;
  touch: (path: string) => void;
  messages: string[];
  serverExtras: Record<string, string[]>;
  domId: string;
}>();

function quantity(id: number): number {
  return form.extras[String(id)] ?? 0;
}

function setQuantity(id: number, value: number): void {
  form.extras[String(id)] = value;
}

function priceLabel(price: string): string {
  return `$${price}`;
}

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Extras"
    :step="4"
    hint="Optional add-ons. Choose how many you want; prices are added to your summary."
    :messages="messages"
  >
    <p
      v-if="extras.length === 0"
      class="text-sm"
    >
      There are no extras for this event.
    </p>
    <div
      v-for="extra in extras"
      :key="extra.id"
      class="rounded-sm border border-border p-3"
    >
      <p class="font-medium text-heading">
        {{ extra.name }}
        <span class="font-normal text-muted">{{ priceLabel(extra.price) }}</span>
      </p>
      <p
        v-if="extra.description"
        class="mt-1 text-sm"
      >
        {{ extra.description }}
      </p>
      <div
        v-if="extraBounds(extra).checkbox"
        class="mt-3 flex items-center gap-2"
      >
        <input
          :id="`extra-${extra.id}`"
          type="checkbox"
          class="h-4 w-4"
          :checked="quantity(extra.id) === 1"
          :disabled="readOnly"
          :aria-invalid="fieldError(`extras.${extra.id}`) ? 'true' : undefined"
          :aria-describedby="fieldError(`extras.${extra.id}`) ? `extra-${extra.id}-error` : undefined"
          @change="setQuantity(extra.id, ($event.target as HTMLInputElement).checked ? 1 : 0)"
        >
        <label :for="`extra-${extra.id}`">Add {{ extra.name }}</label>
      </div>
      <div
        v-else
        class="mt-3 max-w-xs"
      >
        <FormField
          :id="`extra-${extra.id}-quantity`"
          v-slot="{ inputProps }"
          label="Quantity"
          :hint="extraBounds(extra).max === null ? undefined : `From ${extraBounds(extra).min} to ${extraBounds(extra).max}.`"
          :errors="messageList(fieldError(`extras.${extra.id}`), serverExtras[String(extra.id)])"
        >
          <input
            v-bind="inputProps"
            type="number"
            inputmode="numeric"
            class="form-control"
            :min="extraBounds(extra).min"
            :max="extraBounds(extra).max ?? undefined"
            step="1"
            :value="quantity(extra.id)"
            :disabled="readOnly"
            @input="setQuantity(extra.id, Number(($event.target as HTMLInputElement).value))"
            @blur="touch(`extras.${extra.id}`)"
          >
        </FormField>
      </div>
      <p
        v-if="extraBounds(extra).checkbox && fieldError(`extras.${extra.id}`)"
        :id="`extra-${extra.id}-error`"
        class="mt-1 text-sm text-danger-fg"
      >
        {{ fieldError(`extras.${extra.id}`) }}
      </p>
    </div>
  </SectionShell>
</template>
