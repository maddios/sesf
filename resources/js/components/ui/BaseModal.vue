<script setup lang="ts">
/**
 * Accessible modal dialog: role="dialog" + aria-modal, labelled by its title. While open it traps Tab focus,
 * closes on Escape or a backdrop click, locks page scroll, and on close returns focus to the element that had
 * it before opening. Controlled with v-model:open; emits `close` whenever the user dismisses it.
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    size?: 'sm' | 'md' | 'lg';
    closeLabel?: string;
    /** Clicking the backdrop dismisses the dialog. */
    closeOnBackdrop?: boolean;
  }>(),
  { size: 'md', closeLabel: 'Close dialog', closeOnBackdrop: true },
);

const emit = defineEmits<{ 'update:open': [open: boolean]; close: [] }>();

const titleId = useId();
const dialog = ref<HTMLElement | null>(null);
let returnFocusTo: HTMLElement | null = null;
let previousOverflow = '';

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'iframe',
  '[tabindex]:not([tabindex="-1"])',
  '[contenteditable="true"]',
].join(',');

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl' } as const;

function focusables(): HTMLElement[] {
  return Array.from(dialog.value?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter(
    (el) => !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true',
  );
}

function dismiss(): void {
  emit('update:open', false);
  emit('close');
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    dismiss();
    return;
  }
  if (event.key !== 'Tab') {
    return;
  }
  const items = focusables();
  if (items.length === 0) {
    event.preventDefault();
    dialog.value?.focus();
    return;
  }
  const first = items[0] as HTMLElement;
  const last = items[items.length - 1] as HTMLElement;
  const active = document.activeElement;
  if (event.shiftKey && (active === first || active === dialog.value)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

function onBackdrop(): void {
  if (props.closeOnBackdrop) {
    dismiss();
  }
}

function lockScroll(): void {
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
}

function unlockScroll(): void {
  document.body.style.overflow = previousOverflow;
}

async function onOpened(): Promise<void> {
  returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  lockScroll();
  await nextTick();
  const autofocus = dialog.value?.querySelector<HTMLElement>('[autofocus], [data-autofocus]');
  (autofocus ?? focusables()[0] ?? dialog.value)?.focus();
}

function onClosed(): void {
  unlockScroll();
  const target = returnFocusTo;
  returnFocusTo = null;
  if (target !== null && target.isConnected) {
    target.focus();
  }
}

watch(
  () => props.open,
  (open, wasOpen) => {
    if (open && !wasOpen) {
      void onOpened();
    } else if (!open && wasOpen) {
      onClosed();
    }
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (props.open) {
    onClosed();
  }
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center"
    >
      <div
        class="fixed inset-0 bg-black/50"
        aria-hidden="true"
        data-testid="modal-backdrop"
        @click="onBackdrop"
      />
      <div
        ref="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        class="relative w-full rounded-md bg-panel shadow-overlay"
        :class="SIZES[size]"
        @keydown="onKeydown"
      >
        <div class="flex items-center justify-between gap-4 border-b border-border px-5 py-3">
          <h2
            :id="titleId"
            class="text-lg font-semibold text-heading"
          >
            {{ title }}
          </h2>
          <button
            type="button"
            class="-mr-2 rounded-sm px-2 py-1 text-2xl leading-none text-muted hover:bg-subtle hover:text-text"
            :aria-label="closeLabel"
            @click="dismiss"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="px-5 py-4">
          <slot />
        </div>
        <div
          v-if="$slots.footer"
          class="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
