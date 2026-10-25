<script setup lang="ts">
/**
 * Menu button (WAI-ARIA menu pattern). The trigger has aria-haspopup/aria-expanded; the menu is role="menu"
 * with role="menuitem" entries (router links, plain links, or actions). Keyboard: Enter/Space/ArrowDown open on
 * the first item, ArrowUp on the last; Up/Down/Home/End move; Escape closes and returns focus to the trigger;
 * Tab or a click outside closes. Action items emit `select` with their id.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';

export interface DropdownMenuItem {
  id: string;
  label: string;
  /** Hash route (router link). */
  to?: RouteLocationRaw;
  /** Full page link. */
  href?: string;
  /** Draw a separator above this item. */
  separator?: boolean;
}

withDefaults(
  defineProps<{
    label: string;
    items: DropdownMenuItem[];
    align?: 'left' | 'right';
    triggerClass?: string;
  }>(),
  { align: 'right', triggerClass: '' },
);

const emit = defineEmits<{ select: [id: string] }>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const menuId = useId();
const triggerId = useId();

function menuItems(): HTMLElement[] {
  return Array.from(menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
}

async function openMenu(focus: 'first' | 'last' | 'none' = 'first'): Promise<void> {
  open.value = true;
  await nextTick();
  const items = menuItems();
  if (focus === 'first') {
    items[0]?.focus();
  } else if (focus === 'last') {
    items[items.length - 1]?.focus();
  }
}

function closeMenu(returnFocus: boolean): void {
  if (!open.value) {
    return;
  }
  open.value = false;
  if (returnFocus) {
    trigger.value?.focus();
  }
}

function onTriggerClick(): void {
  if (open.value) {
    closeMenu(false);
  } else {
    void openMenu('first');
  }
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    void openMenu('first');
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    void openMenu('last');
  }
}

function onMenuKeydown(event: KeyboardEvent): void {
  const items = menuItems();
  const index = items.indexOf(document.activeElement as HTMLElement);
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      items[(index + 1) % items.length]?.focus();
      break;
    case 'ArrowUp':
      event.preventDefault();
      items[(index - 1 + items.length) % items.length]?.focus();
      break;
    case 'Home':
      event.preventDefault();
      items[0]?.focus();
      break;
    case 'End':
      event.preventDefault();
      items[items.length - 1]?.focus();
      break;
    case 'Escape':
      event.preventDefault();
      closeMenu(true);
      break;
    case 'Tab':
      closeMenu(false);
      break;
  }
}

function onAction(item: DropdownMenuItem): void {
  closeMenu(true);
  emit('select', item.id);
}

function onActionKeydown(event: KeyboardEvent, item: DropdownMenuItem): void {
  if (event.key === ' ') {
    event.preventDefault();
    onAction(item);
  }
}

function onDocumentPointer(event: Event): void {
  if (open.value && root.value !== null && !root.value.contains(event.target as Node)) {
    closeMenu(false);
  }
}

onMounted(() => document.addEventListener('mousedown', onDocumentPointer));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentPointer));

const itemClass = 'block w-full px-4 py-2 text-left text-sm text-text no-underline hover:bg-subtle hover:text-heading focus:bg-subtle';
</script>

<template>
  <div
    ref="root"
    class="relative inline-block"
  >
    <button
      :id="triggerId"
      ref="trigger"
      type="button"
      aria-haspopup="menu"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="menuId"
      :class="triggerClass"
      @click="onTriggerClick"
      @keydown="onTriggerKeydown"
    >
      <slot name="trigger">
        {{ label }}
      </slot>
      <svg
        class="ml-1 inline h-3 w-3 transition-transform"
        :class="open ? 'rotate-180' : ''"
        viewBox="0 0 20 20"
        fill="currentColor"
        aria-hidden="true"
        focusable="false"
      >
        <path d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" />
      </svg>
    </button>
    <ul
      v-show="open"
      :id="menuId"
      ref="menu"
      role="menu"
      :aria-labelledby="triggerId"
      class="absolute z-40 mt-1 min-w-44 rounded-sm border border-border bg-panel py-1 shadow-overlay"
      :class="align === 'right' ? 'right-0' : 'left-0'"
      @keydown="onMenuKeydown"
    >
      <template
        v-for="item in items"
        :key="item.id"
      >
        <li
          v-if="item.separator"
          role="separator"
          class="my-1 border-t border-border"
        />
        <li role="none">
          <RouterLink
            v-if="item.to !== undefined"
            :to="item.to"
            role="menuitem"
            tabindex="-1"
            :class="itemClass"
            @click="closeMenu(false)"
          >
            {{ item.label }}
          </RouterLink>
          <a
            v-else-if="item.href !== undefined"
            :href="item.href"
            role="menuitem"
            tabindex="-1"
            :class="itemClass"
          >{{ item.label }}</a>
          <button
            v-else
            type="button"
            role="menuitem"
            tabindex="-1"
            :class="itemClass"
            @click="onAction(item)"
            @keydown="onActionKeydown($event, item)"
          >
            {{ item.label }}
          </button>
        </li>
      </template>
    </ul>
  </div>
</template>
