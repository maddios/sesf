<script setup lang="ts">
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SectionShell from './SectionShell.vue';
import { messageList } from './fieldErrors';
import { nextRowKey, type ShirtRow } from '../../composables/registration/form';
import { useFormValues } from './formContext';
import type { IndexedMessages, ShirtType } from '../../api/registration';

const props = defineProps<{
  shirtTypes: ShirtType[];
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

function priceOf(id: number | null): string {
  const type = props.shirtTypes.find((item) => item.id === id);
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
            v-for="type in shirtTypes"
            :key="type.id"
            :value="type.id"
          >
            {{ type.name }}
          </option>
        </select>
        <span class="text-sm text-muted">{{ priceOf(item.row.type) }}</span>
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
