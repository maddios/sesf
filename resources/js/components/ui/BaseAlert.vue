<script setup lang="ts">
/**
 * Status message box in the legacy Bootstrap palette (darkened for AA contrast).
 * Errors are role="alert"; other variants are role="status". Both are polite live regions.
 * `dismissible` adds a close button that emits `dismiss`.
 */
import { computed } from 'vue';

export type AlertVariant = 'error' | 'success' | 'info' | 'warning';

const props = withDefaults(
  defineProps<{
    variant?: AlertVariant;
    title?: string;
    dismissible?: boolean;
    dismissLabel?: string;
  }>(),
  { variant: 'error', title: undefined, dismissible: false, dismissLabel: 'Dismiss message' },
);

const emit = defineEmits<{ dismiss: [] }>();

const VARIANTS: Record<AlertVariant, string> = {
  error: 'border-danger-border bg-danger-bg text-danger-fg',
  success: 'border-success-border bg-success-bg text-success-fg',
  info: 'border-info-border bg-info-bg text-info-fg',
  warning: 'border-warning-border bg-warning-bg text-warning-fg',
};

const classes = computed(() => VARIANTS[props.variant]);
</script>

<template>
  <div
    :role="variant === 'error' ? 'alert' : 'status'"
    aria-live="polite"
    class="flex items-start gap-3 rounded-sm border px-4 py-3 text-sm"
    :class="classes"
  >
    <div class="min-w-0 flex-1 space-y-1">
      <p
        v-if="title"
        class="font-semibold"
      >
        {{ title }}
      </p>
      <slot />
    </div>
    <button
      v-if="dismissible"
      type="button"
      class="-my-1 -mr-2 rounded-sm px-2 py-1 text-lg leading-none text-current hover:bg-black/5"
      :aria-label="dismissLabel"
      @click="emit('dismiss')"
    >
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
</template>
