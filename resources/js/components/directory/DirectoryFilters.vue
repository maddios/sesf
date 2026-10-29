<script setup lang="ts">
/**
 * Filter panel for the member and car directories. Select fields apply as soon as they change; the text
 * field applies on submit (Enter or the Search button). Each change is emitted as a partial query patch
 * (keys are the query-string names: show_id, body_id, name); the page decides where it is stored.
 */
import { reactive, useId, watch } from 'vue';
import type { ListFilterOption } from '../../api/cars';
import type { DirectoryParams } from '../../composables/directory/directoryQuery';

export type FilterField =
  | { kind: 'select'; name: 'show_id' | 'body_id'; label: string; options: ListFilterOption[] }
  | { kind: 'text'; name: 'name'; label: string; placeholder?: string };

const props = defineProps<{
  fields: FilterField[];
  values: DirectoryParams;
  /** Accessible name of the filter form, e.g. "Filter members". */
  label: string;
  /** One row above the grid (event tabs) instead of a sidebar column. */
  inline?: boolean;
}>();

const emit = defineEmits<{ change: [patch: Partial<DirectoryParams>] }>();

const baseId = useId();
const draft = reactive<{ name: string }>({ name: props.values.name });

watch(
  () => props.values.name,
  (name) => {
    draft.name = name;
  },
);

function fieldId(name: string): string {
  return `${baseId}-${name}`;
}

function onSelect(name: 'show_id' | 'body_id', event: Event): void {
  emit('change', { [name]: (event.target as HTMLSelectElement).value } as Partial<DirectoryParams>);
}

function onSearch(): void {
  emit('change', { name: draft.name.trim() });
}
</script>

<template>
  <form
    :class="inline ? 'flex flex-wrap items-end gap-x-6 gap-y-3' : 'space-y-4'"
    role="search"
    :aria-label="label"
    @submit.prevent="onSearch"
  >
    <template
      v-for="field in fields"
      :key="field.name"
    >
      <div
        v-if="field.kind === 'select'"
        :class="inline ? 'w-56' : undefined"
      >
        <label
          :for="fieldId(field.name)"
          class="block text-sm font-semibold text-heading"
        >{{ field.label }}</label>
        <select
          :id="fieldId(field.name)"
          class="mt-1 block w-full rounded-sm border border-border-strong bg-panel px-2 py-1.5 text-sm text-text"
          :value="values[field.name]"
          @change="onSelect(field.name, $event)"
        >
          <option value="">
            All
          </option>
          <option
            v-for="option in field.options"
            :key="option.id"
            :value="String(option.id)"
          >
            {{ option.name }}
          </option>
        </select>
      </div>
      <div
        v-else
        :class="inline ? 'w-72' : undefined"
      >
        <label
          :for="fieldId(field.name)"
          class="block text-sm font-semibold text-heading"
        >{{ field.label }}</label>
        <div :class="inline ? 'mt-1 flex gap-2' : undefined">
          <input
            :id="fieldId(field.name)"
            v-model="draft.name"
            type="text"
            :placeholder="field.placeholder"
            autocomplete="off"
            :class="['block w-full rounded-sm border border-border-strong bg-panel px-2 py-1.5 text-sm text-text', inline ? '' : 'mt-1']"
          >
          <button
            type="submit"
            :class="['shrink-0 rounded-sm border border-default-border bg-default px-3 py-1 text-sm text-text hover:bg-default-hover', inline ? '' : 'mt-2']"
          >
            Search
          </button>
        </div>
      </div>
    </template>
  </form>
</template>
