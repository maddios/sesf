<script setup lang="ts">
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { nextRowKey, type ShirtRow } from '../../composables/registration/form';
import { useFormValues } from './formContext';
import type { IndexedMessages, RegisteredShirt, ShirtType } from '../../api/registration';

const props = defineProps<{
  /** The types this event offers, at its prices. */
  shirtTypes: ShirtType[];
  /** The shirts already ordered (saved), with their billed price. */
  registeredShirts: RegisteredShirt[];
  readOnly: boolean;
  fieldError: (path: string) => string | undefined;
  touch: (path: string) => void;
  messages: string[];
  serverShirts: IndexedMessages;
  domId: string;
}>();

function rows(): { row: ShirtRow; index: number; position: number }[] {
  return form.shirts
    .map((row, index) => ({ row, index }))
    .filter(({ row }) => !row.deleted)
    .map((item, position) => ({ ...item, position: position + 1 }));
}

function addShirt(): void {
  form.shirts.push({ key: nextRowKey(), id: null, type: null, deleted: false });
}

function removeShirt(index: number): void {
  const row = form.shirts[index];
  if (row === undefined) {
    return;
  }
  if (row.id !== null) {
    row.deleted = true;
  } else {
    form.shirts.splice(index, 1);
  }
}

function savedShirt(row: ShirtRow): RegisteredShirt | undefined {
  return row.id === null ? undefined : props.registeredShirts.find((shirt) => shirt.id === row.id);
}

/** The event's types, plus the saved type of this shirt when the event no longer offers it (it stays billed). */
function optionsFor(row: ShirtRow): ShirtType[] {
  const saved = savedShirt(row);
  if (saved === undefined || saved.type === null || props.shirtTypes.some((type) => type.id === saved.type)) {
    return props.shirtTypes;
  }
  return [...props.shirtTypes, { id: saved.type, name: `${saved.name} (no longer offered)`, price: saved.price }];
}

/** A saved shirt keeps the price it was ordered at; a new or changed one costs the event's price. */
function priceOf(row: ShirtRow): string {
  const saved = savedShirt(row);
  if (saved !== undefined && saved.type === row.type) {
    return ` ($${saved.price})`;
  }
  const type = props.shirtTypes.find((item) => item.id === row.type);
  return type === undefined ? '' : ` ($${type.price})`;
}

const form = useFormValues();
</script>

<template>
  <SectionShell
    :dom-id="domId"
    title="T-shirts"
    :step="3"
    hint="Pick a size for each shirt. Prices are added to your summary."
    :messages="messages"
  >
    <p
      v-if="rows().length === 0"
      class="text-sm"
    >
      No shirts added.
    </p>
    <fieldset
      v-for="item in rows()"
      :key="item.row.key"
      class="space-y-3 rounded-sm border border-border p-3"
    >
      <legend class="px-1 text-sm font-semibold text-heading">
        Shirt {{ item.position }}
      </legend>
      <ResponseBanner :messages="serverShirts[item.index] ?? []" />
      <FormField
        :id="`shirt-${item.row.key}-type`"
        v-slot="{ inputProps }"
        label="Size"
        :errors="messageList(fieldError(`shirts.${item.index}.type`))"
      >
        <select
          v-bind="inputProps"
          class="form-control max-w-xs"
          :value="item.row.type ?? ''"
          :disabled="readOnly"
          @change="item.row.type = Number(($event.target as HTMLSelectElement).value) || null"
          @blur="touch(`shirts.${item.index}.type`)"
        >
          <option value="">
            Please select
          </option>
          <option
            v-for="type in optionsFor(item.row)"
            :key="type.id"
            :value="type.id"
          >
            {{ type.name }}
          </option>
        </select>
        <span class="text-sm text-muted">{{ priceOf(item.row) }}</span>
      </FormField>
      <button
        type="button"
        class="text-sm font-medium text-brand underline underline-offset-2 disabled:opacity-60"
        :disabled="readOnly"
        :aria-label="`Remove shirt ${item.position}`"
        @click="removeShirt(item.index)"
      >
        Remove shirt
      </button>
    </fieldset>
    <button
      type="button"
      class="inline-flex rounded-sm border border-default-border bg-default px-3.5 py-1.5 text-sm font-medium text-text hover:bg-default-hover disabled:opacity-60"
      :disabled="readOnly"
      @click="addShirt"
    >
      Add shirt
    </button>
  </SectionShell>
</template>
