<script setup lang="ts">
/**
 * Disclosure accordion. Each header is a button with aria-expanded/aria-controls inside a heading; each panel
 * is a labelled region. `multiple` lets several panels stay open; otherwise opening one closes the others.
 * Up/Down/Home/End move focus between headers. Panel content comes from the `panel` slot ({ item }) or a
 * slot named after the item id.
 */
import { ref, useId, watch } from 'vue';

export interface AccordionItem {
  id: string;
  title: string;
}

const props = withDefaults(
  defineProps<{
    items: AccordionItem[];
    multiple?: boolean;
    /** Ids open initially (and when this prop changes). */
    defaultOpen?: string[];
    headingLevel?: 2 | 3 | 4 | 5 | 6;
  }>(),
  { multiple: false, defaultOpen: () => [], headingLevel: 3 },
);

const emit = defineEmits<{ change: [openIds: string[]] }>();

const baseId = useId();
const openIds = ref<string[]>([]);
const root = ref<HTMLElement | null>(null);

watch(
  () => props.defaultOpen,
  (ids) => {
    openIds.value = props.multiple ? [...ids] : ids.slice(0, 1);
  },
  { immediate: true },
);

function isOpen(id: string): boolean {
  return openIds.value.includes(id);
}

function toggle(id: string): void {
  if (isOpen(id)) {
    openIds.value = openIds.value.filter((value) => value !== id);
  } else {
    openIds.value = props.multiple ? [...openIds.value, id] : [id];
  }
  emit('change', [...openIds.value]);
}

function onKeydown(event: KeyboardEvent): void {
  const headers = Array.from(root.value?.querySelectorAll<HTMLElement>('[data-accordion-header]') ?? []);
  const index = headers.indexOf(document.activeElement as HTMLElement);
  if (index === -1) {
    return;
  }
  const keys: Record<string, number> = {
    ArrowDown: (index + 1) % headers.length,
    ArrowUp: (index - 1 + headers.length) % headers.length,
    Home: 0,
    End: headers.length - 1,
  };
  const next = keys[event.key];
  if (next !== undefined) {
    event.preventDefault();
    headers[next]?.focus();
  }
}

const headerId = (id: string) => `${baseId}-h-${id}`;
const panelId = (id: string) => `${baseId}-p-${id}`;
</script>

<template>
  <div
    ref="root"
    class="divide-y divide-border rounded-md border border-border bg-panel"
    @keydown="onKeydown"
  >
    <div
      v-for="item in items"
      :key="item.id"
    >
      <component
        :is="`h${headingLevel}`"
        class="m-0"
      >
        <button
          :id="headerId(item.id)"
          type="button"
          data-accordion-header
          class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left font-semibold text-heading hover:bg-subtle"
          :aria-expanded="isOpen(item.id) ? 'true' : 'false'"
          :aria-controls="panelId(item.id)"
          @click="toggle(item.id)"
        >
          <span>{{ item.title }}</span>
          <svg
            class="h-4 w-4 shrink-0 transition-transform"
            :class="isOpen(item.id) ? 'rotate-180' : ''"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
            focusable="false"
          >
            <path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" />
          </svg>
        </button>
      </component>
      <div
        v-show="isOpen(item.id)"
        :id="panelId(item.id)"
        role="region"
        :aria-labelledby="headerId(item.id)"
        class="px-4 pb-4 pt-1"
      >
        <slot
          :name="item.id"
          :item="item"
        >
          <slot
            name="panel"
            :item="item"
          />
        </slot>
      </div>
    </div>
  </div>
</template>
