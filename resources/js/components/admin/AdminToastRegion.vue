<script setup lang="ts">
/**
 * Global toast stack for useToast(). The container is always in the DOM as a polite live region, so messages
 * added later are announced by screen readers. Each toast has a dismiss button.
 */
import { useToast } from '../../composables/admin/useToast';

const { toasts, dismiss } = useToast();

const VARIANTS = {
  success: 'border-success-border bg-success-bg text-success-fg',
  error: 'border-danger-border bg-danger-bg text-danger-fg',
  info: 'border-info-border bg-info-bg text-info-fg',
} as const;
</script>

<template>
  <div
    role="status"
    aria-live="polite"
    aria-label="Notifications"
    class="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:left-auto sm:w-96"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="pointer-events-auto flex w-full items-start gap-3 rounded-sm border px-4 py-3 text-sm shadow-overlay"
      :class="VARIANTS[toast.variant]"
      :data-variant="toast.variant"
    >
      <p class="min-w-0 flex-1">
        {{ toast.message }}
      </p>
      <button
        type="button"
        class="-my-1 -mr-2 rounded-sm px-2 py-1 text-lg leading-none hover:bg-black/5"
        aria-label="Dismiss notification"
        @click="dismiss(toast.id)"
      >
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  </div>
</template>
