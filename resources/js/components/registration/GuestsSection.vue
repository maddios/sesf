<script setup lang="ts">
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { nextRowKey, type GuestRow } from '../../composables/registration/form';
import { useFormValues } from './formContext';
import { guestHint } from '../../composables/registration/validation';
import type { IndexedMessages, NamedOption } from '../../api/registration';

const props = defineProps<{
  guestTypes: NamedOption[];
  carCount: number;
  readOnly: boolean;
  fieldError: (path: string) => string | undefined;
  touch: (path: string) => void;
  messages: string[];
  serverGuests: IndexedMessages;
  domId: string;
}>();

/** Index in form.guests (server errors use it), with the visible position for the "Guest N" label. */
const rows = (): { row: GuestRow; index: number; position: number }[] =>
  form.guests
    .map((row, index) => ({ row, index }))
    .filter(({ row }) => !row.deleted)
    .map((item, position) => ({ ...item, position: position + 1 }));

function addGuest(): void {
  form.guests.push({ key: nextRowKey(), id: null, first: '', last: '', email: '', type: null, deleted: false });
}

function removeGuest(index: number): void {
  const row = form.guests[index];
  if (row === undefined) {
    return;
  }
  if (row.id !== null) {
    // Saved guests are marked deleted so the server removes them and the indices stay aligned.
    row.deleted = true;
  } else {
    form.guests.splice(index, 1);
  }
}

function guestErrors(index: number, field: 'first' | 'last' | 'email' | 'type'): string[] {
  return messageList(props.fieldError(`guests.${index}.${field}`));
}

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="Guests"
    :step="2"
    :hint="guestHint(carCount)"
    :messages="messages"
  >
    <p
      v-if="rows().length === 0"
      class="text-sm"
    >
      No guests added.
    </p>
    <fieldset
      v-for="item in rows()"
      :key="item.row.key"
      class="space-y-3 rounded-sm border border-border p-3"
    >
      <legend class="px-1 text-sm font-semibold text-heading">
        Guest {{ item.position }}
      </legend>
      <ResponseBanner :messages="serverGuests[item.index] ?? []" />
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          :id="`guest-${item.row.key}-first`"
          v-slot="{ inputProps }"
          label="First name"
          :errors="guestErrors(item.index, 'first')"
        >
          <input
            v-bind="inputProps"
            type="text"
            class="form-control"
            autocomplete="off"
            :value="item.row.first"
            :disabled="readOnly"
            @input="item.row.first = ($event.target as HTMLInputElement).value"
            @blur="touch(`guests.${item.index}.first`)"
          >
        </FormField>
        <FormField
          :id="`guest-${item.row.key}-last`"
          v-slot="{ inputProps }"
          label="Last name"
          :errors="guestErrors(item.index, 'last')"
        >
          <input
            v-bind="inputProps"
            type="text"
            class="form-control"
            autocomplete="off"
            :value="item.row.last"
            :disabled="readOnly"
            @input="item.row.last = ($event.target as HTMLInputElement).value"
            @blur="touch(`guests.${item.index}.last`)"
          >
        </FormField>
        <FormField
          :id="`guest-${item.row.key}-email`"
          v-slot="{ inputProps }"
          label="Email (optional)"
          hint="Used only for the guest waiver. It must differ from your own email."
          :errors="guestErrors(item.index, 'email')"
        >
          <input
            v-bind="inputProps"
            type="email"
            class="form-control"
            autocomplete="off"
            :value="item.row.email"
            :disabled="readOnly"
            @input="item.row.email = ($event.target as HTMLInputElement).value"
            @blur="touch(`guests.${item.index}.email`)"
          >
        </FormField>
        <FormField
          :id="`guest-${item.row.key}-type`"
          v-slot="{ inputProps }"
          label="Guest type"
          :errors="guestErrors(item.index, 'type')"
        >
          <select
            v-bind="inputProps"
            class="form-control"
            :value="item.row.type ?? ''"
            :disabled="readOnly"
            @change="item.row.type = Number(($event.target as HTMLSelectElement).value) || null"
            @blur="touch(`guests.${item.index}.type`)"
          >
            <option value="">
              Please select
            </option>
            <option
              v-for="type in guestTypes"
              :key="type.id"
              :value="type.id"
            >
              {{ type.name }}
            </option>
          </select>
        </FormField>
      </div>
      <button
        type="button"
        class="text-sm font-medium text-brand underline underline-offset-2 disabled:opacity-60"
        :disabled="readOnly"
        :aria-label="`Remove guest ${item.position}`"
        @click="removeGuest(item.index)"
      >
        Remove guest
      </button>
    </fieldset>
    <button
      type="button"
      class="inline-flex rounded-sm border border-default-border bg-default px-3.5 py-1.5 text-sm font-medium text-text hover:bg-default-hover disabled:opacity-60"
      :disabled="readOnly"
      @click="addGuest"
    >
      Add guest
    </button>
  </SectionShell>
</template>
