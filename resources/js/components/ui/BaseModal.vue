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
    size?: 'sm' | 'md' | 'lg' | 'xl';
    closeLabel?: string;
    /** Clicking the backdrop dismisses the dialog. */
    closeOnBackdrop?: boolean;
    /**
     * Phone-friendly sheet: below 768px the dialog is a full-width bottom sheet capped at the viewport height; the
     * body scrolls and the footer stays visible with stacked full-width buttons. From 768px it is a centred dialog
     * whose body scrolls inside the viewport.
     */
    sheet?: boolean;
  }>(),
  { size: 'md', closeLabel: 'Close dialog', closeOnBackdrop: true, sheet: false },
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

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl', xl: 'max-w-site' } as const;

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
      class="fixed inset-0 z-50 flex justify-center"
      :class="sheet ? 'items-end md:items-center md:p-4' : 'items-start overflow-y-auto p-4 sm:items-center'"
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
        class="relative w-full bg-panel shadow-overlay"
        :class="[SIZES[size], sheet ? 'flex max-h-[100dvh] flex-col rounded-t-xl md:max-h-[calc(100dvh-2rem)] md:rounded-md' : 'rounded-md']"
        @keydown="onKeydown"
      >
        <div
          class="flex items-center justify-between gap-4 border-b border-border px-5 py-3"
          :class="sheet ? 'shrink-0 px-4 md:px-5' : ''"
        >
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
            :title="closeLabel"
            @click="dismiss"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div
          class="@container px-5 py-4"
          :class="sheet ? 'min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 md:px-5' : ''"
        >
          <slot />
        </div>
        <div
          v-if="$slots.footer"
          class="flex gap-2 border-t border-border px-5 py-3"
          :class="sheet ? 'shrink-0 flex-col-reverse px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:flex-row md:flex-wrap md:justify-end md:px-5 [&>*]:w-full md:[&>*]:w-auto' : 'flex-wrap justify-end'"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
