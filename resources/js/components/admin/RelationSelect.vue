<script setup lang="ts">
/**
 * Async searchable select (ARIA 1.2 combobox + listbox) over an admin-api lookup endpoint that answers
 * GET {endpoint}?search=text and GET {endpoint}?ids=1 with { data: [{ id, label }] }.
 *
 * - v-model is the chosen id (number) or null. On mount with a value, the label is fetched with ?ids=.
 * - Typing searches after a short pause; ArrowDown/ArrowUp move, Enter picks, Escape closes (and a second Escape
 *   restores the chosen label), Tab leaves. A clear button resets to null.
 * - Attributes from FormField (id, aria-describedby, aria-invalid) go on the input.
 */
import { computed, onBeforeUnmount, onMounted, ref, useAttrs, useId, watch } from 'vue';
import { api } from '../../api/client';
import { mapAdminError } from '../../composables/admin/useAdminResource';

export interface RelationOption {
  id: number;
  label: string;
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
  }>(),
  { placeholder: 'Search…', disabled: false, debounceMs: 250, ariaLabel: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null]; change: [option: RelationOption | null] }>();

const attrs = useAttrs();
const listboxId = useId();
const fallbackId = useId();
const inputId = computed(() => (typeof attrs.id === 'string' ? attrs.id : fallbackId));

const text = ref('');
const selected = ref<RelationOption | null>(null);
const options = ref<RelationOption[]>([]);
const open = ref(false);
const active = ref(-1);
const loading = ref(false);
const problem = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const activeId = computed(() => (open.value && active.value >= 0 ? `${listboxId}-opt-${active.value}` : undefined));

async function fetchOptions(query: string): Promise<RelationOption[]> {
  const body = await api.get<{ data?: RelationOption[] }>(`${props.endpoint}?${query}`);
  return Array.isArray(body.data) ? body.data : [];
}

async function search(term: string): Promise<void> {
  const mine = ++sequence;
  loading.value = true;
  problem.value = null;
  try {
    const found = await fetchOptions(new URLSearchParams({ search: term }).toString());
    if (mine !== sequence) return;
    options.value = found;
    active.value = found.length > 0 ? 0 : -1;
    open.value = true;
  } catch (error) {
    if (mine !== sequence) return;
    options.value = [];
    problem.value = mapAdminError(error, 'Options could not be loaded.').message;
    open.value = true;
  } finally {
    if (mine === sequence) loading.value = false;
  }
}

async function resolveLabel(id: number | null): Promise<void> {
  if (id === null) {
    selected.value = null;
    text.value = '';
    return;
  }
  if (selected.value?.id === id) return;
  try {
    const found = await fetchOptions(new URLSearchParams({ ids: String(id) }).toString());
    selected.value = found.find((option) => option.id === id) ?? { id, label: `#${id}` };
  } catch {
    selected.value = { id, label: `#${id}` };
  }
  text.value = selected.value.label;
}

function onInput(): void {
  clearTimeout(timer);
  timer = setTimeout(() => void search(text.value.trim()), props.debounceMs);
}

function choose(option: RelationOption | null): void {
  selected.value = option;
  text.value = option?.label ?? '';
  open.value = false;
  active.value = -1;
  emit('update:modelValue', option?.id ?? null);
  emit('change', option);
}

function move(step: number): void {
  if (!open.value) {
    void search(text.value.trim());
    return;
  }
  if (options.value.length === 0) return;
  active.value = (active.value + step + options.value.length) % options.value.length;
}

function onKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      move(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      move(-1);
      break;
    case 'Enter':
      if (open.value && active.value >= 0) {
        event.preventDefault();
        choose(options.value[active.value] ?? null);
      }
      break;
    case 'Escape':
      if (open.value) {
        event.preventDefault();
        event.stopPropagation();
        open.value = false;
      } else if (text.value !== (selected.value?.label ?? '')) {
        event.preventDefault();
        event.stopPropagation();
        text.value = selected.value?.label ?? '';
      }
      break;
    case 'Tab':
      open.value = false;
      break;
  }
}

function onBlur(): void {
  // Let a click on an option land before closing.
  setTimeout(() => {
    open.value = false;
    if (text.value.trim() === '' && selected.value !== null) {
      choose(null);
    } else {
      text.value = selected.value?.label ?? '';
    }
  }, 150);
}

watch(
  () => props.modelValue,
  (id) => void resolveLabel(id),
);

onMounted(() => void resolveLabel(props.modelValue));
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <div class="relative">
    <div class="flex items-center gap-1">
      <input
        v-bind="$attrs"
        :id="inputId"
        v-model="text"
        type="text"
        role="combobox"
        class="form-control w-full"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-label="ariaLabel"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="listboxId"
        :aria-activedescendant="activeId"
        :aria-busy="loading ? 'true' : undefined"
        :placeholder="placeholder"
        :disabled="disabled"
        @input="onInput"
        @keydown="onKeydown"
        @blur="onBlur"
      >
      <button
        v-if="selected !== null && !disabled"
        type="button"
        class="rounded-sm px-2 py-1 text-lg leading-none text-muted hover:bg-subtle hover:text-text"
        :aria-label="`Clear ${selected.label}`"
        @click="choose(null)"
      >
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
    <ul
      v-show="open"
      :id="listboxId"
      role="listbox"
      :aria-labelledby="inputId"
      class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-sm border border-border bg-panel py-1 shadow-overlay"
    >
      <li
        v-for="(option, index) in options"
        :id="`${listboxId}-opt-${index}`"
        :key="option.id"
        role="option"
        :aria-selected="index === active ? 'true' : 'false'"
        class="cursor-pointer px-3 py-1.5 text-sm"
        :class="index === active ? 'bg-brand-tint text-brand-dark' : 'text-text hover:bg-subtle'"
        @mousedown.prevent="choose(option)"
      >
        {{ option.label }}
      </li>
      <li
        v-if="options.length === 0"
        role="option"
        aria-disabled="true"
        aria-selected="false"
        class="px-3 py-1.5 text-sm text-muted"
      >
        {{ problem ?? (loading ? 'Searching…' : 'No matches') }}
      </li>
    </ul>
  </div>
</template>
