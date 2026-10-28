<script setup lang="ts">
/**
 * Searchable dropdown over an admin-api lookup endpoint that answers GET {endpoint}?search=text and
 * GET {endpoint}?ids=1 with { data: [{ id, label }] }. A thin wrapper of BaseCombobox: opening shows the first
 * results, typing searches (debounced), the chosen label is fetched with ?ids= on mount, the clear button resets
 * to null. Attributes from FormField (id, aria-describedby, aria-invalid) go on the input.
 */
import BaseCombobox, { type ComboboxOption, type ComboboxValue } from '../ui/BaseCombobox.vue';
import { api } from '../../api/client';

export interface RelationOption {
  id: number;
  label: string;
  [extra: string]: unknown;
}

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    modelValue: number | null;
    endpoint: string;
    placeholder?: string;
    disabled?: boolean;
    debounceMs?: number;
    /** Accessible name when the input has no <label for> (e.g. inside a filter bar). */
    ariaLabel?: string;
    /** Show the clear button (default on: lookups are usually optional filters). */
    clearable?: boolean;
  }>(),
  { placeholder: 'Search…', disabled: false, debounceMs: 250, ariaLabel: undefined, clearable: true },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null]; change: [option: RelationOption | null] }>();

const loaded = new Map<number, RelationOption>();

async function fetchOptions(query: string): Promise<ComboboxOption[]> {
  const body = await api.get<{ data?: RelationOption[] }>(`${props.endpoint}?${query}`);
  const rows = Array.isArray(body.data) ? body.data : [];
  rows.forEach((row) => loaded.set(row.id, row));
  return rows.map((row) => ({ value: row.id, label: row.label }));
}

function search(term: string): Promise<ComboboxOption[]> {
  return fetchOptions(new URLSearchParams({ search: term }).toString());
}

async function resolve(value: ComboboxValue): Promise<ComboboxOption | null> {
  const found = await fetchOptions(new URLSearchParams({ ids: String(value) }).toString());
  return found.find((option) => String(option.value) === String(value)) ?? null;
}

function onChange(option: ComboboxOption | null): void {
  const id = option === null ? null : Number(option.value);
  emit('update:modelValue', id);
  emit('change', id === null ? null : (loaded.get(id) ?? { id, label: option!.label }));
}
</script>

<template>
  <BaseCombobox
    v-bind="$attrs"
    :model-value="modelValue"
    :search="search"
    :resolve="resolve"
    :placeholder="placeholder"
    :disabled="disabled"
    :debounce-ms="debounceMs"
    :aria-label="ariaLabel"
    :clearable="clearable"
    @change="onChange"
  />
</template>
