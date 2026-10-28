<script setup lang="ts">
/**
 * Accessible dropdown with type-to-filter (WAI-ARIA 1.2 combobox with a listbox popup).
 *
 * - Looks and opens like a select: a click or ArrowDown/Alt+ArrowDown opens the list, the caret toggles it, the
 *   chosen label stays visible. Typing filters the list.
 * - Options: static `options` (filtered by label on the client) or async `search(term)` (debounced; also called
 *   with '' when the list opens) plus `resolve(value)` to fetch the label of a value that is not loaded yet.
 * - Keys: ArrowUp/ArrowDown move (opening if closed), Home/End jump while open, Enter picks, Escape closes (a
 *   second Escape restores the chosen label), Tab leaves. Leaving with edited text restores the chosen label; an
 *   emptied field clears an optional value.
 * - `clearable` shows a clear button while a value is set. Attributes (id, aria-describedby, aria-invalid, name...)
 *   go on the input, so <label for> and FormField work as with a native control.
 * - The list is teleported to <body> and positioned under (or above) the input, so dialogs and scroll areas never
 *   clip it. Phones get 16px text (no zoom on focus) and 44px rows.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, useId, watch } from 'vue';

export type ComboboxValue = string | number;

export interface ComboboxOption {
  value: ComboboxValue;
  label: string;
  /** Second line under the label (e.g. an email). */
  description?: string;
  disabled?: boolean;
}

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    modelValue: ComboboxValue | null;
    options?: ComboboxOption[];
    search?: (term: string) => Promise<ComboboxOption[]>;
    resolve?: (value: ComboboxValue) => Promise<ComboboxOption | null>;
    placeholder?: string;
    disabled?: boolean;
    clearable?: boolean;
    /** Accessible name when there is no <label for>. */
    ariaLabel?: string;
    debounceMs?: number;
    emptyText?: string;
    /** Extra classes for the visible field (e.g. a smaller filter style). */
    inputClass?: string;
  }>(),
  {
    options: () => [],
    search: undefined,
    resolve: undefined,
    placeholder: undefined,
    disabled: false,
    clearable: false,
    ariaLabel: undefined,
    debounceMs: 250,
    emptyText: 'No matches',
    inputClass: '',
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: ComboboxValue | null]; change: [option: ComboboxOption | null] }>();

const attrs = useAttrs();
const fallbackId = useId();
const listboxId = useId();
const inputId = computed(() => (typeof attrs.id === 'string' ? attrs.id : fallbackId));
const rootClass = computed(() => attrs.class as string | undefined);
const rootStyle = computed(() => attrs.style as string | undefined);
const inputAttrs = computed(() => {
  const rest: Record<string, unknown> = { ...attrs };
  delete rest.class;
  delete rest.style;
  return rest;
});

const input = ref<HTMLInputElement | null>(null);
const field = ref<HTMLElement | null>(null);
const text = ref('');
const typed = ref(false);
const open = ref(false);
const active = ref(-1);
const loading = ref(false);
const problem = ref<string | null>(null);
const remote = ref<ComboboxOption[]>([]);
const selected = ref<ComboboxOption | null>(null);
const position = ref<{ top: string; left: string; width: string; maxHeight: string }>({ top: '0', left: '0', width: '0', maxHeight: '16rem' });
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const isAsync = computed(() => props.search !== undefined);
const visible = computed<ComboboxOption[]>(() => {
  if (isAsync.value) return remote.value;
  const term = typed.value ? text.value.trim().toLowerCase() : '';
  return term === '' ? props.options : props.options.filter((option) => option.label.toLowerCase().includes(term));
});
const activeId = computed(() => (open.value && active.value >= 0 ? `${listboxId}-opt-${active.value}` : undefined));
const status = computed(() => {
  if (problem.value !== null) return problem.value;
  if (loading.value && visible.value.length === 0) return 'Searching…';
  return visible.value.length === 0 ? props.emptyText : null;
});

function same(a: ComboboxValue | null | undefined, b: ComboboxValue | null | undefined): boolean {
  return a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b);
}

async function resolveSelected(value: ComboboxValue | null): Promise<void> {
  const known = value === null ? undefined : [...props.options, ...remote.value].find((option) => same(option.value, value));
  if (value === null || (value === '' && known === undefined)) {
    selected.value = null;
  } else if (known !== undefined) {
    selected.value = known;
  } else if (!same(selected.value?.value, value)) {
    if (props.resolve !== undefined) {
      try {
        selected.value = (await props.resolve(value)) ?? { value, label: `#${value}` };
      } catch {
        selected.value = { value, label: `#${value}` };
      }
    } else {
      selected.value = { value, label: String(value) };
    }
  }
  if (!typed.value) text.value = selected.value?.label ?? '';
}

function firstEnabled(from: number, step: number): number {
  const list = visible.value;
  for (let index = from, tries = 0; tries < list.length; index = (index + step + list.length) % list.length, tries++) {
    if (list[index]?.disabled !== true) return index;
  }
  return -1;
}

function placeList(): void {
  const box = field.value?.getBoundingClientRect();
  if (box === undefined) return;
  const below = window.innerHeight - box.bottom - 8;
  const above = box.top - 8;
  const up = below < 200 && above > below;
  const room = Math.max(120, Math.min(320, up ? above : below));
  position.value = {
    top: up ? `${Math.max(8, box.top - room - 4)}px` : `${box.bottom + 4}px`,
    left: `${box.left}px`,
    width: `${box.width}px`,
    maxHeight: `${room}px`,
  };
}

async function runSearch(term: string): Promise<void> {
  if (props.search === undefined) return;
  const mine = ++sequence;
  loading.value = true;
  problem.value = null;
  try {
    const found = await props.search(term);
    if (mine !== sequence) return;
    remote.value = found;
    active.value = firstEnabled(0, 1);
  } catch {
    if (mine !== sequence) return;
    remote.value = [];
    problem.value = 'Options could not be loaded.';
  } finally {
    if (mine === sequence) loading.value = false;
  }
}

function show(): void {
  if (props.disabled) return;
  if (!open.value) {
    open.value = true;
    placeList();
  }
  if (isAsync.value) {
    void runSearch(typed.value ? text.value.trim() : '');
  } else {
    const current = visible.value.findIndex((option) => same(option.value, selected.value?.value));
    active.value = current >= 0 ? current : firstEnabled(0, 1);
  }
  void nextTick(scrollActive);
}

function hide(): void {
  open.value = false;
  active.value = -1;
}

function toggle(): void {
  if (open.value) {
    hide();
  } else {
    input.value?.focus();
    show();
  }
}

function choose(option: ComboboxOption | null): void {
  if (option?.disabled === true) return;
  selected.value = option;
  typed.value = false;
  text.value = option?.label ?? '';
  hide();
  emit('update:modelValue', option?.value ?? null);
  emit('change', option);
}

function onInput(): void {
  typed.value = true;
  if (!open.value) {
    open.value = true;
    placeList();
  }
  if (isAsync.value) {
    clearTimeout(timer);
    timer = setTimeout(() => void runSearch(text.value.trim()), props.debounceMs);
  } else {
    active.value = firstEnabled(0, 1);
  }
}

function move(step: number): void {
  if (!open.value) {
    show();
    return;
  }
  if (visible.value.length === 0) return;
  const start = active.value < 0 ? (step > 0 ? 0 : visible.value.length - 1) : (active.value + step + visible.value.length) % visible.value.length;
  active.value = firstEnabled(start, step);
  void nextTick(scrollActive);
}

function scrollActive(): void {
  if (activeId.value === undefined) return;
  document.getElementById(activeId.value)?.scrollIntoView?.({ block: 'nearest' });
}

function restoreText(): void {
  typed.value = false;
  text.value = selected.value?.label ?? '';
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
    case 'Home':
    case 'End':
      if (open.value && visible.value.length > 0) {
        event.preventDefault();
        active.value = event.key === 'Home' ? firstEnabled(0, 1) : firstEnabled(visible.value.length - 1, -1);
        void nextTick(scrollActive);
      }
      break;
    case 'Enter':
      if (open.value && active.value >= 0) {
        event.preventDefault();
        choose(visible.value[active.value] ?? null);
      }
      break;
    case 'Escape':
      if (open.value) {
        event.preventDefault();
        event.stopPropagation();
        hide();
      } else if (text.value !== (selected.value?.label ?? '')) {
        event.preventDefault();
        event.stopPropagation();
        restoreText();
      }
      break;
    case 'Tab':
      hide();
      break;
  }
}

function onBlur(): void {
  // A click on an option uses mousedown.prevent, so focus never leaves for it; blur means the user left.
  hide();
  if (typed.value && text.value.trim() === '' && selected.value !== null) {
    choose(null);
  } else {
    restoreText();
  }
}

function onWindowChange(): void {
  if (open.value) placeList();
}

watch(
  () => props.modelValue,
  (value) => void resolveSelected(value),
  { immediate: true },
);
watch(
  () => props.options,
  () => void resolveSelected(props.modelValue),
);

onMounted(() => {
  window.addEventListener('resize', onWindowChange);
  window.addEventListener('scroll', onWindowChange, true);
});

onBeforeUnmount(() => {
  clearTimeout(timer);
  window.removeEventListener('resize', onWindowChange);
  window.removeEventListener('scroll', onWindowChange, true);
});
</script>

<template>
  <div
    ref="field"
    class="relative"
    :class="rootClass"
    :style="rootStyle"
  >
    <input
      v-bind="inputAttrs"
      :id="inputId"
      ref="input"
      v-model="text"
      type="text"
      role="combobox"
      class="form-control w-full cursor-default pr-14 text-ellipsis max-sm:text-base"
      :class="[inputClass, disabled ? '' : 'focus:cursor-text']"
      autocomplete="off"
      autocapitalize="off"
      spellcheck="false"
      aria-autocomplete="list"
      :aria-label="ariaLabel"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="listboxId"
      :aria-activedescendant="activeId"
      :aria-busy="loading ? 'true' : undefined"
      :placeholder="placeholder"
      :disabled="disabled"
      @click="open ? undefined : show()"
      @input="onInput"
      @keydown="onKeydown"
      @blur="onBlur"
    >
    <div class="absolute inset-y-0 right-1 flex items-center">
      <button
        v-if="clearable && selected !== null && !disabled"
        type="button"
        class="flex h-7 w-6 items-center justify-center rounded-sm text-lg leading-none text-muted hover:bg-subtle hover:text-text"
        :aria-label="`Clear ${selected.label}`"
        @mousedown.prevent
        @click="choose(null)"
      >
        <span aria-hidden="true">&times;</span>
      </button>
      <button
        type="button"
        tabindex="-1"
        class="flex h-7 w-6 items-center justify-center rounded-sm text-muted hover:text-text disabled:opacity-50"
        :aria-label="open ? 'Hide options' : 'Show options'"
        :disabled="disabled"
        @mousedown.prevent
        @click="toggle"
      >
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          class="h-4 w-4 transition-transform"
          :class="open ? 'rotate-180' : ''"
        ><path
          fill="currentColor"
          d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4Z"
        /></svg>
      </button>
    </div>
    <Teleport to="body">
      <ul
        v-show="open"
        :id="listboxId"
        role="listbox"
        :aria-labelledby="ariaLabel ? undefined : inputId"
        :aria-label="ariaLabel"
        class="fixed z-[70] overflow-y-auto overscroll-contain rounded-sm border border-border-strong bg-panel py-1 text-sm shadow-overlay max-sm:text-base"
        :style="position"
      >
        <li
          v-for="(option, index) in visible"
          :id="`${listboxId}-opt-${index}`"
          :key="String(option.value)"
          role="option"
          :aria-selected="index === active ? 'true' : 'false'"
          :aria-disabled="option.disabled ? 'true' : undefined"
          class="flex cursor-pointer items-start gap-2 px-3 py-1.5 max-sm:min-h-11 max-sm:items-center"
          :class="[
            index === active ? 'bg-brand-tint text-brand-dark' : 'text-text hover:bg-subtle',
            option.disabled ? 'cursor-not-allowed opacity-50' : '',
          ]"
          @mousedown.prevent="choose(option)"
          @mousemove="active = index"
        >
          <span
            class="w-4 shrink-0 text-brand"
            aria-hidden="true"
          >{{ same(option.value, selected?.value) ? '✓' : '' }}</span>
          <span class="min-w-0">
            <span class="block">{{ option.label }}</span>
            <span
              v-if="option.description"
              class="block text-xs text-muted"
            >{{ option.description }}</span>
          </span>
        </li>
        <li
          v-if="status !== null"
          role="option"
          aria-disabled="true"
          aria-selected="false"
          class="px-3 py-1.5 text-muted"
        >
          {{ status }}
        </li>
      </ul>
    </Teleport>
  </div>
</template>
